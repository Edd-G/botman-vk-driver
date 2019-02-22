<?php

namespace BotMan\Drivers\Vk;

use BotMan\BotMan\Drivers\Events\GenericEvent;
use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\BotMan\Messages\Outgoing\Question;
use BotMan\BotMan\Users\User;
use BotMan\Drivers\Vk\Exceptions\VkException;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

class VkDriver extends HttpDriver
{
    const DRIVER_NAME = 'Vk';
    const API_URL = 'https://api.vk.com/method/';
    const GENERIC_EVENTS = [
        'confirmation',
        'chat_create',
        'chat_invite_user',
        'chat_invite_user_by_link',
        'chat_kick_user',
        'chat_photo_remove',
        'chat_photo_update',
        'chat_pin_message',
        'chat_title_update',
        'chat_unpin_message',
        'message_allow',
        'message_deny',
        'message_edit',
        'message_reply',
    ];
    const CONFIRMATION_EVENT = 'confirmation';

    protected $messages = [];
    private static $one_time = false;

    /**
     * Convert a Question object into a valid
     * quick reply response object.
     *
     * @param \BotMan\BotMan\Messages\Outgoing\Question $question
     * @return array
     */
    private function convertQuestion(Question $question)
    {
        $replies = Collection::make($question->getButtons())->map(function ($button) {
            if(isset($button['additional']['onetime'])) {
                self::$one_time = true;
                unset($button['additional']['onetime']);
            }
            $action = [
                'action' => [
                    'type' => 'text', 
                    'payload' => json_encode(['command' => (string) $button['value']], JSON_UNESCAPED_UNICODE), 
                    'label' => (string) $button['text']
                ]
            ];
            return [
                array_merge($action, $button['additional']),
            ];
        });
        return $replies->toArray();
    }

    public function buildPayload(Request $request)
    {
        $this->payload = new ParameterBag(json_decode($request->getContent(), true) ?? []);
        $this->event = Collection::make($this->payload->all());
        $this->config = Collection::make($this->config->get('vk', []));
        if (($this->matchesRequest() || $this->hasMatchingEvent()) && $this->event->get('type') != self::CONFIRMATION_EVENT) {
            $this->respondApiServer();
        } elseif ($this->event->get('type') === self::CONFIRMATION_EVENT && $this->requestAuthenticated()) {
            $this->sendConfirmation();
        }
    }

    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        //TODO: Implement attachments features.
        $payload = array_merge_recursive([
            'peer_id' => $matchingMessage->getSender(),
        ], $additionalParameters);

        if ($message instanceof Question) {
            $payload['message'] = $message->getText();
            $payload['keyboard'] = json_encode([
                'buttons' => $this->convertQuestion($message),
                'one_time' => self::$one_time,
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($message instanceof OutgoingMessage) {
            $payload['message'] = $message->getText();
        } else {
            $payload['message'] = $message;
        }

        return $payload;
    }

    public function getConversationAnswer(IncomingMessage $message)
    {
        $object = $this->event->get('object');
        
        if (isset($object['payload'])) {
            $callback = json_decode($object['payload'], true);
            if (isset($callback['command'])) {
                return Answer::create($object['text'])
                    ->setInteractiveReply(true)
                    ->setMessage($message)
                    ->setValue($callback['command']);
            }
        }
        return Answer::create($message->getText())->setMessage($message);
    }

    public function getMessages()
    {
        if (empty($this->messages)) {
            $this->loadMessages();
        }
        return $this->messages;
    }

    protected function loadMessages()
    {
        $message = $this->event->get('object');

        // Handle payload command from VK
        if (isset($message['payload'])) {
            $callback = json_decode($message['payload'], true);
            $messagetext = isset($callback['command']) ? $callback['command'] : $message['text'];
        } else {
            $messagetext = $message['text'];
        }
        $this->messages = [new IncomingMessage($messagetext, $message['from_id'], $message['peer_id'], $this->event->toArray())];
    }

    public function getUser(IncomingMessage $matchingMessage)
    {
        $payload = [
            'user_ids' => $matchingMessage->getSender(),
            'fields' => 'screen_name, city, contacts'
        ];

        $response = $this->sendRequest('users.get', $payload, new IncomingMessage('', '', ''));
        $responseData = json_decode($response->getContent(), true);

        if ($response->getStatusCode() != 200) {
            throw new VkException('HTTP error occured.', $response->getStatusCode());
        } elseif (isset($responseData['error'])) {
            throw new VkException('Vk API error occured.', $response['error']['error_code']);
        }
        $user = $responseData['response'][0];

        return new User($user['id'], $user['first_name'], $user['last_name'], $user['screen_name'], $user);
    }

    public function hasMatchingEvent()
    {
        if(!$this->requestAuthenticated()) {
            return false;
        }
        $event = false;

        // At first we check "direct" events from vk API, such as
        // confirmation or message_edit. After that we check chat's
        // events (that vk API send inside message_new event).
        if (in_array($this->event->get('type'), self::GENERIC_EVENTS)) {
            $event = new GenericEvent($this->event->get('object') ?? []);
            $event->setName($this->event->get('type'));
        } elseif (in_array($this->event->toArray()['object']['action']['type'] ?? '', self::GENERIC_EVENTS)) {
            $chatAction = Collection::make($this->event->toArray()['object']['action']);
            $event = new GenericEvent($chatAction->except('type'));
            $event->setName($this->event->toArray()['object']['action']['type']);
        }

        return $event;
    }

    public function isConfigured()
    {
        return !empty($this->config->get('access_token')) && !empty($this->config->get('api_version'));
    }

    public function matchesRequest()
    {
        return ($this->event->get('type') == 'message_new') && !isset($this->event->toArray()['object']['action']) && $this->requestAuthenticated();
    }

    protected function respondApiServer()
    {
        static $responseSent = false;
        if (!$responseSent) {
            echo 'ok';
            $responseSent = true;
        }
    }

    public function requestAuthenticated()
    {
        return ($this->config->get('secret_key') == $this->event->get('secret'));
    }


    public function sendConfirmation()
    {
        if ($this->event->get('group_id') == $this->config->get('group_id')) {
            exit($this->config->get('confirmation'));
        }
    }

    public function sendPayload($payload)
    {
        return $this->sendRequest('messages.send', $payload, new IncomingMessage('', '', ''));
    }

    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        $parameters['access_token'] = $this->config->get('access_token');
        $parameters['v'] = $this->config->get('api_version');
        $parameters['lang'] = $this->config->get('lang');
        return $this->http->post(self::API_URL . $endpoint, [], $parameters);
    }

}