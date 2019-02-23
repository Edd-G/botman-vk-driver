<?php

namespace BotMan\Drivers\Vk;

use BotMan\BotMan\Drivers\Events\GenericEvent;
use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Interfaces\VerifiesService;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\BotMan\Messages\Outgoing\Question;
//use BotMan\BotMan\Storages\Storage;
use BotMan\BotMan\Users\User;
use BotMan\Drivers\Vk\Exceptions\VkException;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class VkDriver extends HttpDriver implements VerifiesService
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
        'message_edit',
        'message_reply',
    ];
    const ACTION_EVENTS = [ // Not used yet
        'message_deny', // Watch users deny message
        'message_new', // Watch incoming message
    ];
    const CONFIRMATION_EVENT = 'confirmation';

    protected $messages = [];
    private static $one_time = false;

    
    // TODO: implement MUTE bot if group admin's write message in conversation
    // for that, store mute timer in cache
    // private function saveStorage($data)
    // {
    //        
    // }

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
        $this->payload = new ParameterBag((array) json_decode($request->getContent(), true) ?? []);
        $this->event = Collection::make($this->payload->all());
        $this->config = Collection::make($this->config->get('vk', []));
        $this->queryParameters = Collection::make($request->query);
        $this->content = $request->getContent();
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

        // Handle payload command from VK. (buttons payload)
        if (isset($message['payload'])) {
            $callback = json_decode($message['payload'], true);
            $messagetext = isset($callback['command']) ? $callback['command'] : $message['text'];
        } elseif (isset($message['text'])) {
            $messagetext = $message['text'];
        // If user deny recive message - pass this to bot logic
        // TODO: add config
        } elseif ($this->event->get('type') == 'message_deny') {
            $messagetext = '_message_deny';
            $message['from_id'] = $message['user_id'];
            $message['peer_id'] = $message['user_id'];
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
        //TODO: move attachments check to file driver
        //$noAttachments = isset($this->event->toArray()['object']['attachments']) ?
        $matches = (!is_null($this->event->get('type')) || !is_null($this->event->get('object'))) && !is_null($this->event->get('group_id')) && $this->requestAuthenticated();
        if ($matches && $this->event->get('type') != self::CONFIRMATION_EVENT)
        {
            $this->respondApiServer();
        }
        return $matches;
    }

    private function respondApiServer()
    {
        echo 'ok';
    }

    public function requestAuthenticated()
    {
        return ($this->config->get('secret_key') == $this->event->get('secret'));
    }

    /**
     * @param Request $request
     * @return Response|null
     */
    public function verifyRequest(Request $request)
    {
        if ($this->payload->get('type') === self::CONFIRMATION_EVENT &&
            $this->payload->get('group_id') == $this->config->get('group_id')) {
            return Response::create($this->config->get('confirmation'))->send();
        }
        return null;
    }

    /**
     * @param IncomingMessage $matchingMessage
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function types(IncomingMessage $matchingMessage)
    {
        $parameters = [
            'user_id' => $this->config->get('group_id'),
            'type' => 'typing',
            'peer_id' => $matchingMessage->getSender(),
        ];
        return $this->sendRequest('messages.setActivity', $parameters, new IncomingMessage('', '', ''));
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
