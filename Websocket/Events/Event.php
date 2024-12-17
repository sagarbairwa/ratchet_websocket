<?php

namespace App\Http\Websocket\Events;

use App\Http\Websocket\WebSocketController;
use Ratchet\ConnectionInterface;
use Exception;
use Log;

abstract class Event implements EventInterface
{
    /**
     * Array of attributes passed to an event
     *
     * @var array $attrs
     */
    private $attrs = [];

    /**
     * Event text to be sent
     *
     * @var string $event
     */
    public $event = 'event';

    /**
     * Event name to be sent
     *
     * @var string $name
     */
    public $name = null;

    /**
     * Event controller
     *
     * @var string $controller
     */
    protected $controller;

    /**
     * Current socket connection which raised the event
     *
     * @var $conn
     */
    protected $conn;

    /**
     * Event constructor
     *
     * @param array $attrs
     *
     * @return void
     */
    public function __construct(array $attrs = [])
    {
        $this->attrs = $attrs;
        $this->event =  (new \ReflectionClass($this))->getShortName();
    }

    /**
     * Set attribute values to $attr array
     *
     * @param string $attr
     * @param mixed $val
     *
     * @return void
     */
    public function __set(string $attr, $val)
    {
        $this->attrs[$attr] = $val;
    }

    /**
     * get attribute values
     *
     * @param string $attr
     * @param null $default
     *
     * @return mixed
     */
    public function get(string $attr, $default = null)
    {
        if (isset($this->attrs[$attr])) {
            return $this->attrs[$attr];
        }
        return $default;
    }

    /**
     * Get attributes
     *
     * @return array
     */
    public function getAttributes()
    {
        return $this->attrs;
    }

    /**
     * Get attributes
     *
     * @param string $attr
     *
     * @return mixed
     */
    public function __get(string $attr)
    {
        if (isset($this->attrs[$attr])) {
            return $this->attrs[$attr];
        }

        return null;
    }

    /**
     * Get event
     *
     * @return mixed
     */
    public function getEvent()
    {
        if (!is_null($this->name)) {
            return "{$this->event}.{$this->name}";
        }

        return $this->event;
    }

    /**
     * Convert socket data to JSON string
     *
     * @return string
     */
    public function __toString()
    {
        return json_encode(
            [
                'event' => $this->getEvent(),
                'data' => $this->attrs,
            ]
        );
    }

    /**
     * Set controller to be used
     *
     * @return void
     */
    public function setController(WebSocketController $controller)
    {
        $this->controller = $controller;
    }

    /**
     * Set connetion over which data to e sent
     *
     * @return void
     */
    public function setConnection(ConnectionInterface $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Get user from current connection
     *
     * @return User object
     */
    protected function user()
    {
        if (!$this->conn || !$this->conn->authorized) {
            return false;
        }

        return $this->conn->user;
    }

    /**
     * Handler of the event if event not found
     *
     * @return void
     */
    protected function handle()
    {
        echo 'No handler for event ' .get_called_class();
        Log::info('No handler for event ' .get_called_class());
    }

    /**
     * Dispatch the event
     *
     * @return void
     */
    public function dispatch()
    {
        $this->handle();
    }
}
