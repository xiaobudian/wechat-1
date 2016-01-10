<?php

/*
 * This file is part of the overtrue/wechat.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Guard.php.
 *
 * @author    overtrue <i@overtrue.me>
 * @copyright 2015 overtrue <i@overtrue.me>
 *
 * @link      https://github.com/overtrue
 * @link      http://overtrue.me
 */
namespace EasyWeChat\Server;

use EasyWeChat\Core\Exceptions\FaultException;
use EasyWeChat\Core\Exceptions\InvalidArgumentException;
use EasyWeChat\Core\Exceptions\RuntimeException;
use EasyWeChat\Encryption\Encryptor;
use EasyWeChat\Message\AbstractMessage;
use EasyWeChat\Message\Raw as RawMessage;
use EasyWeChat\Message\Text;
use EasyWeChat\Support\Collection;
use EasyWeChat\Support\Log;
use EasyWeChat\Support\XML;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class Guard.
 */
class Guard
{
    /**
     * Empty string.
     */
    const SUCCESS_EMPTY_RESPONSE = 'success';

    const TEXT_MSG = 2;
    const IMAGE_MSG = 4;
    const VOICE_MSG = 8;
    const VIDEO_MSG = 16;
    const SHORT_VIDEO_MSG = 32;
    const LOCATION_MSG = 64;
    const LINK_MSG = 128;
    const EVENT_MSG = 1048576;
    const ALL_MSG = 1048830;

    /**
     * Request instance.
     *
     * @var Request
     */
    protected $request;

    /**
     * Encryptor instance.
     *
     * @var Encryptor
     */
    protected $encryptor;

    /**
     * Message listener.
     *
     * @var string|callable
     */
    protected $messageHandler;

    /**
     * Message type mapping.
     *
     * @var array
     */
    protected $messageTypeMapping = [
        'text' => 2,
        'image' => 4,
        'voice' => 8,
        'video' => 16,
        'shortvideo' => 32,
        'location' => 64,
        'link' => 128,
        'event' => 1048576,
    ];

    /**
     * Constructor.
     *
     * @param Request $request
     */
    public function __construct(Request $request = null)
    {
        $this->request = $request ?: Request::createFromGlobals();
    }

    /**
     * Handle and return response.
     *
     * @return Response
     *
     * @throws BadRequestException
     */
    public function serve()
    {
        Log::debug('Request received:', [
            'Method' => $this->request->getMethod(),
            'URI' => $this->request->getRequestUri(),
            'Query' => $this->request->getQueryString(),
            'Protocal' => $this->request->server->get('SERVER_PROTOCOL'),
            'Content' => $this->request->getContent(),
        ]);

        if ($str = $this->request->get('echostr')) {
            Log::info("Output 'echostr' is '$str'.");

            return new Response($str);
        }

        $result = $this->handleRequest();

        $response = $this->buildResponse($result['to'], $result['from'], $result['response']);

        Log::debug('Server response created:', compact('response'));

        return new Response($response);
    }

    /**
     * Validation request params.
     *
     * @param string $token
     *
     * @throws FaultException
     */
    public function validate($token)
    {
        $params = [
            $token,
            $this->request->get('timestamp'),
            $this->request->get('nonce'),
        ];

        if ($this->request->get('signature') !== $this->signature($params)) {
            throw new FaultException('Invalid request signature.', 400);
        }
    }

    /**
     * Add a event listener.
     *
     * @param callable $callback
     * @param int      $option
     *
     * @return Guard
     *
     * @throws InvalidArgumentException
     */
    public function setMessageHandler($callback = null, $option = self::ALL_MSG)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Argument #2 is not callable.');
        }

        $this->messageHandler = $callback;
        $this->messageFilter = $option;

        return $this;
    }

    /**
     * Return the message listener.
     *
     * @return string
     */
    public function getMessageHandler()
    {
        return $this->messageHandler;
    }

    /**
     * Set Encryptor.
     *
     * @param Encryptor $encryptor
     *
     * @return Guard
     */
    public function setEncryptor(Encryptor $encryptor)
    {
        $this->encryptor = $encryptor;

        return $this;
    }

    /**
     * Return the encryptor instance.
     *
     * @return Encryptor
     */
    public function getEncryptor()
    {
        return $this->encryptor ?: $this->encryptor = new Encryptor(
                                    $this->options['app_id'],
                                    $this->options['token'],
                                    $this->options['aes_key']
                                    );
    }

    /**
     * Build response.
     *
     * @param mixed $message
     *
     * @return string
     */
    protected function buildResponse($to, $from, $message)
    {
        if (empty($message) || $message === self::SUCCESS_EMPTY_RESPONSE) {
            return self::SUCCESS_EMPTY_RESPONSE;
        }

        if ($message instanceof RawMessage) {
            return $message->get('content', self::SUCCESS_EMPTY_RESPONSE);
        }

        if (is_string($message)) {
            $message = new Text(['content' => $message]);
        }

        if ($this->isMessage($message)) {
            $response = $this->buildReply($to, $from, $message);

            if ($this->isSafeMode()) {
                Log::info('Message safe mode is enable.');
                $response = $this->encryptor->encryptMsg(
                    $response,
                    $this->request->get('nonce'),
                    $this->request->get('timestamp')
                );
            }
        }

        return $response;
    }

    /**
     * Whether response is message.
     *
     * @param mixed $response
     *
     * @return bool
     */
    protected function isMessage($response)
    {
        if(is_array($response)){
            foreach ($response as $element) {
                if(!is_subclass_of($element, AbstractMessage::class)){
                    return false;
                }
            }
            return true;
        }else{
            return is_subclass_of($response, AbstractMessage::class);
        }
    }

    /**
     * Handle request.
     *
     * @return array
     *
     * @throws \EasyWeChat\Core\Exceptions\RuntimeException
     * @throws \EasyWeChat\Server\BadRequestException
     */
    protected function handleRequest()
    {
        $message = $this->parseMessageFromRequest($this->request->getContent(false));

        if (empty($message)) {
            throw new BadRequestException('Invalid request.');
        }

        $response = $this->handleMessage($message);

        return [
            'to' => $message['FromUserName'],
            'from' => $message['ToUserName'],
            'response' => $response,
        ];
    }

    /**
     * Handle message.
     *
     * @param array $message
     *
     * @return mixed
     */
    protected function handleMessage($message)
    {
        $handler = $this->messageHandler;

        if (!is_callable($handler)) {
            Log::debug('No handler enabled.');

            return;
        }

        Log::debug('Message detail:', $message);

        $message = new Collection($message);

        $type = $this->messageTypeMapping[$message->get('MsgType')];

        $response = null;

        if ($this->messageFilter & $type) {
            $response = call_user_func_array($handler, [$message]);
        }

        return $response;
    }

    /**
     * Build reply XML.
     *
     * @param string          $to
     * @param string          $from
     * @param AbstractMessage $message
     *
     * @return string
     */
    protected function buildReply($to, $from, $message)
    {
        $base = [
            'ToUserName' => $to,
            'FromUserName' => $from,
            'CreateTime' => time(),
            'MsgType' => is_array($message) ? current($message)->getType() : $message->getType(),
        ];

        $transformer = new Transformer();

        return XML::build(array_merge($base, $transformer->transform($message)));
    }

    /**
     * Get signature.
     *
     * @param array $request
     *
     * @return string
     */
    protected function signature($request)
    {
        sort($request, SORT_STRING);

        return sha1(implode($request));
    }

    /**
     * Parse message array from raw php input.
     *
     * @param string $content
     *
     * @throws \EasyWeChat\Core\Exceptions\RuntimeException
     * @throws \EasyWeChat\Encryption\EncryptionException
     *
     * @return array
     */
    protected function parseMessageFromRequest($content)
    {
        if ($this->isSafeMode()) {
            if (!$this->encryptor) {
                throw new RuntimeException('Safe mode Encryptor is necessary.');
            }

            $message = $this->encryptor->decryptMsg(
                $this->request->get('msg_signature'),
                $this->request->get('nonce'),
                $this->request->get('timestamp'),
                $content
            );
        } else {
            $message = XML::parse($content);
        }

        return $message;
    }

    /**
     * Check the request message safe mode.
     *
     * @return bool
     */
    private function isSafeMode()
    {
        return $this->request->get('encrypt_type') && $this->request->get('encrypt_type') === 'aes';
    }
}
