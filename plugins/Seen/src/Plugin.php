<?php
namespace Muffin\Bot\Seen;

use Cake\Datasource\ConnectionManager;
use Cake\Database\Schema\Table as Schema;
use Cake\ORM\TableRegistry;
use Evenement\EventEmitter;
use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Bot\React\EventQueueInterface;
use Phergie\Irc\Event\CtcpEventInterface;
use Phergie\Irc\Event\ServerEventInterface;
use Phergie\Irc\Event\UserEventInterface;
use Phergie\Irc\Plugin\React\Command\CommandEvent;

class Plugin extends AbstractPlugin
{

    const TYPE_JOIN = 0;
    const TYPE_PART = 1;
    const TYPE_KICK = 2;
    const TYPE_QUIT = 3;
    const TYPE_NICK = 4;
    const TYPE_PRIVMSG = 5;
    const TYPE_NOTICE = 6;
    const TYPE_ACTION = 7;

    protected $channels = [];

    protected $connection = 'default';

    protected $table;

    public function __construct(array $config = []) 
    {
        if (isset($config['connection'])) {
            $this->connection = $config['connection'];
        }

        $connection = ConnectionManager::get($this->connection);
        $tables = $connection->schemaCollection()->listTables();
        $columns = [
            'time' => 'integer',
            'server' => 'string',
            'channel' => 'string',
            'nick' => 'string',
            'type' => 'integer',
            'message' => 'text',
        ];

        if (!in_array('seen_logs', $tables)) {
            $schema = new Schema('seen_logs', $columns);
            $schema->addConstraint('primary', [
                'type' => 'primary', 
                'columns' => ['server', 'channel', 'nick'],
            ]);
            $connection->query(implode(';', $schema->createSql($connection)));
        }

        $this->table = TableRegistry::get('SeenLogs', [
            'connection' => $connection,
            'table' => 'seen_logs',
        ]);
        $this->table->primaryKey(['server', 'channel', 'nick']);
    }

    /**
     * Returns a crude time ago string.
     *
     * @param int $time Origin timestamp
     *
     * @return string
     */
    protected function ago($time)
    {
        $time = time() - $time;

        if ($time < 60) {
            return 'a moment ago';
        }

        $secs = [
            'year'   => 365.25 * 24 * 60 * 60,
            'month'  => 30 * 24 * 60 * 60,
            'week'   => 7 * 24 * 60 * 60,
            'day'    => 24 * 60 * 60,
            'hour'   => 60 * 60,
            'minute' => 60,
        ];

        foreach ($secs as $str => $s) {
            $d = $time / $s;

            if ($d >= 1) {
                $r = round($d);
                return sprintf('%d %s%s ago', $r, $str, ($r > 1) ? 's' : '');
            }
        }
    }

    /**
     * Checks whether a given nickname is currently on a channel.
     *
     * @param string $server
     * @param string $channel
     * @param string $user (case-insensitive)
     *
     * @return bool
     */
    protected function isInChannel($server, $channel, $user)
    {
        if (!isset($this->channels[$server][$channel]))
        {
            return false;
        }

        $names = array_keys($this->channels[$server][$channel], true, true);
        foreach ($names as $name) {
            if (!strcasecmp($name, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns event subscriptions.
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        // No point in listening if we can't do anything with it...
        if (!$this->connection) {
            return [];
        }

        return [
            'command.seen' => 'handleCommand',
            'command.seen.help' => 'handleCommandHelp',
            'irc.received.join' => 'processJoin',
            'irc.received.part' => 'processPart',
            'irc.received.kick' => 'processKick',
            'irc.received.quit' => 'processQuit',
            'irc.received.nick' => 'processNick',
            'irc.received.privmsg' => 'processPrivmsg',
            'irc.received.notice' => 'processNotice',
            'irc.received.ctcp.action' => 'processAction',
            'irc.received.rpl_namreply' => 'processNames',
        ];
    }

    /**
     * Handles the seen command.
     *
     * @param \Phergie\Irc\Plugin\React\Command\CommandEvent $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function handleCommand(CommandEvent $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();

        $server = strtolower($event->getConnection()->getServerHostname());
        $source = $event->getSource();
        $nick = $event->getNick();
        if ($source === null || $nick === null || $source == $nick) {
            $logger->debug('Command request not in channel, ignoring');
            return;
        }

        $params = $event->getCustomParams();
        if (empty($params)) {
            $this->handleCommandHelp($event, $queue);
            return;
        }

        $target = $params[0];

        $seen = $this->table->find()
            ->select(['time', 'nick', 'type', 'message'])
            ->where(compact('server', 'channel') + ['OR' => [
                'nick' => $target,
                [
                    'type' => SELF::TYPE_NICK,
                    'message' => $target
                ]
            ]])
            ->order(['time' => 'DESC']);

        if (!$seen->count()) {
            $queue->ircPrivmsg($source, "I haven't seen \x02$target\x02 in $source!");
            return;
        }

        $seenEntity = $seen->first();

        switch ($seenEntity->type) {
            case static::TYPE_JOIN:
                $message = 'joining the channel.';
                break;

            case static::TYPE_PART:
                if ($seenEntity->message) {
                    $message = 'leaving the channel (' . $seenEntity->message . ')';
                    break;
                }
                $message = 'leaving the channel.';
                break;

            case static::TYPE_KICK:
                if ($seenEntity->message) {
                    $message = 'beeing kicked from the channel (' . $seenEntity->message . ')';
                    break;
                }
                $message = 'being kicked from the channel.';
                break;

            case static::TYPE_QUIT:
                if ($seenEntity->message) {
                    $message = 'disconnecting from IRC (' . $seenEntity->message . ')';
                    break;
                }
                $message = 'disconnecting from IRC.';
                break;

            case static::TYPE_PRIVMSG:
                $message = 'saying: ' . $seenEntity->message;
                break;

            case static::TYPE_NOTICE:
                $message = 'sending a notice: ' . $seenEntity->message;
                break;

            case static::TYPE_ACTION:
                $message = 'saying: * .' . $seenEntity->nick . ' ' . $seenEntity->message;
                break;

            case static::TYPE_NICK:
                if (!strcasecmp($target, $seenEntity->nick)) {
                    $target = $seenEntity->nick;
                    $message = 'changing nick to ' . $seenEntity->message;
                    break;
                }
                $target = $seenEntity->message;
                $message = 'changing nick from ' . $seenEntity->nick;
                break;

            default:
                $logger->error('Unknown parameter type retrieved from database', $seenEntity);
                $queue->ircPrivmsg($source, "\x02Error:\x02 A database error occurred.");
                return;

        }


        // Canonicalise capitalisation
        if ($seenEntity->type != static::TYPE_NICK) {
            $target = $seenEntity->nick;
        }

        $prefix = $target . ' was last seen ';
        if ($this->isInChannel($server, $source, $target)) {
            $prefix = $target . ' is currently in the channel!';
            if ($message) {
                $prefix .= ' Was last seen ';
            }
        }

        $suffix = implode(' ', [$this->ago($seenEntity->time), $message]);
        if (!$message) {
            $suffix = '';
        }

        $queue->ircPrivmsg($source, $prefix . $suffix);
    }

    /**
     * Handles help for the seen command.
     *
     * @param \Phergie\Irc\Plugin\React\Command\CommandEvent $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function handleCommandHelp(CommandEvent $event, EventQueueInterface $queue)
    {
        $queue->ircPrivmsg($event->getSource(), "\x02Usage:\x02 seen <nickname>");
    }

    public function updateDatabase(array $primaryKey, array $data = [])
    {
        $entity = $this->table->findOrCreate($primaryKey);
        $this->table->patchEntity($entity, $data + ['time' => time()]);
        return $this->table->save($entity);
    }

    /**
     * Monitor channel joins.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processJoin(UserEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();

        $nick = $event->getNick();
        $server = strtolower($event->getConnection()->getServerHostname());
        $params = $event->getParams();
        $channel = $params['channels'];
        $type = static::TYPE_JOIN;

        if ($nick == $event->getConnection()->getNickname()) {
            $logger->debug('Nickname of incoming JOIN is ours, ignoring');
            $this->channels[$server][$channel] = [];
            return;
        }

        $logger->debug('Processing incoming JOIN', array(
            'server' => $server,
            'channel' => $channel,
            'nick' => $nick,
        ));

        $this->channels[$server][$channel][$nick] = true;

        if (!$this->updateDatabase(compact('server', 'channel', 'nick'), compact('type'))) {
           $logger->error($e->getMessage());
        }
    }

    /**
     * Monitor channel parts.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processPart(UserEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();

        $nick = $event->getNick();
        $server = strtolower($event->getConnection()->getServerHostname());
        $params = $event->getParams();
        $channel = $params['channels'];
        $message = isset($params['message']) ? $params['message'] : null;
        $type = static::TYPE_PART;

        if ($nick == $event->getConnection()->getNickname()) {
            $logger->debug('Removing channel', array('server' => $server, 'channel' => $channel));
            unset($this->channels[$server][$channel]);
            return;
        }

        $logger->debug('Processing incoming PART', array(
            'server' => $server,
            'channel' => $channel,
            'nick' => $nick,
            'message' => $message,
        ));

        unset($this->channels[$server][$channel][$nick]);

        if (!$this->updateDatabase(compact('server', 'channel', 'nick'), compact('type', 'message'))) {
           $logger->error($e->getMessage());
        }
    }

    /**
     * Monitor channel kicks.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processKick(UserEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();

        $server = strtolower($event->getConnection()->getServerHostname());
        $params = $event->getParams();
        $channel = $params['channel'];
        $nick = $params['user'];
        $message = isset($params['comment']) ? $params['comment'] : null;
        $type = static::TYPE_KICK;

        if ($nick == $event->getConnection()->getNickname()) {
            $logger->debug('Removing channel', array('server' => $server, 'channel' => $channel));
            unset($this->channels[$server][$channel]);
            return;
        }

        $logger->debug('Processing incoming KICK', array(
            'server' => $server,
            'channel' => $channel,
            'nick' => $nick,
            'message' => $message,
        ));

        unset($this->channels[$server][$channel][$nick]);

        if (!$this->updateDatabase(compact('server', 'channel', 'nick'), compact('type', 'message'))) {
           $logger->error($e->getMessage());
        }
    }

    /**
     * Monitor quits.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processQuit(UserEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();

        $nick = $event->getNick();
        if ($nick == $event->getConnection()->getNickname()) {
            $logger->debug('Abandon ship!');
            return;
        }

        $server = strtolower($event->getConnection()->getServerHostname());
        $params = $event->getParams();
        $message = isset($params['message']) ? $params['message'] : null;
        $type = static::TYPE_QUIT;

        $logger->debug('Processing incoming QUIT', array(
            'server' => $server,
            'nick' => $nick,
            'message' => $message,
        ));

        foreach ($this->channels[$server] as $channel => $users) {
            if (isset($users[$nick])) {
                $logger->debug('Removing user from channel', array(
                    'server' => $server,
                    'channel' => $channel,
                    'nick' => $nick,
                ));

                unset($this->channels[$server][$channel][$nick]);

                if (!$this->updateDatabase(compact('server', 'channel', 'nick'), compact('type', 'message'))) {
                   $logger->error($e->getMessage());
                }
            }
        }
    }

    /**
     * Monitor nick changes.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processNick(UserEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();

        $nick = $event->getNick();
        if ($nick == $event->getConnection()->getNickname()) {
            $logger->debug('Nickname of incoming NICK is ours, ignoring');
            return;
        }

        $server = strtolower($event->getConnection()->getServerHostname());
        $params = $event->getParams();
        $newnick = $params['nickname'];
        $type = static::TYPE_NICK;

        $logger->debug('Processing incoming NICK', array(
            'server' => $server,
            'nick' => $nick,
            'newnick' => $newnick,
        ));

        foreach ($this->channels[$server] as $channel => $users) {
            if (isset($users[$nick])) {
                $logger->debug('Processing channel nick change', array(
                    'server' => $server,
                    'channel' => $channel,
                    'nick' => $nick,
                    'newnick' => $newnick,
                ));

                unset($this->channels[$server][$channel][$nick]);
                $this->channels[$server][$channel][$newnick] = true;

                if (!$this->updateDatabase(compact('server', 'channel', 'nick'), compact('type', 'message'))) {
                   $logger->error($e->getMessage());
                }
            }
        }
    }

    /**
     * Monitor channel messages.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processPrivmsg(UserEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();

        $source = $event->getSource();
        $nick = $event->getNick();
        if ($source === null || $nick === null || $source == $nick) {
            $logger->debug('Incoming PRIVMSG not in channel, ignoring');
            return;
        }

        $server = strtolower($event->getConnection()->getServerHostname());
        $channel = $source;
        $params = $event->getParams();
        $message = $params['text'];
        $type = static::TYPE_PRIVMSG;

        $logger->debug('Processing incoming PRIVMSG', array(
            'server' => $server,
            'channel' => $channel,
            'nick' => $nick,
            'message' => $message,
        ));

        if (!$this->updateDatabase(compact('server', 'channel', 'nick'), compact('type', 'message'))) {
           $logger->error($e->getMessage());
        }
    }

    /**
     * Monitor channel notices.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processNotice(UserEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();

        $source = $event->getSource();
        $nick = $event->getNick();
        if ($source === null || $nick === null || $source == $nick) {
            $logger->debug('Incoming NOTICE not in channel, ignoring');
            return;
        }

        $server = strtolower($event->getConnection()->getServerHostname());
        $channel = $source;
        $params = $event->getParams();
        $message = $params['text'];
        $type = static::TYPE_NOTICE;

        $logger->debug('Processing incoming NOTICE', array(
            'server' => $server,
            'channel' => $channel,
            'nick' => $nick,
            'message' => $message,
        ));

        if (!$this->updateDatabase(compact('server', 'channel', 'nick'), compact('type', 'message'))) {
           $logger->error($e->getMessage());
        }
    }

    /**
     * Monitor channel actions.
     *
     * @param \Phergie\Irc\Event\CtcpEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processAction(CtcpEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();

        $source = $event->getSource();
        $nick = $event->getNick();
        if ($source === null || $nick === null || $source == $nick) {
            $logger->debug('Incoming CTCP ACTION not in channel, ignoring');
            return;
        }

        $server = strtolower($event->getConnection()->getServerHostname());
        $channel = $event->getSource();
        $params = $event->getCtcpParams();
        $message = $params['action'];
        $type = static::TYPE_ACTION;

        $logger->debug('Processing incoming CTCP ACTION', array(
            'server' => $server,
            'channel' => $channel,
            'nick' => $nick,
            'message' => $message,
        ));

        if (!$this->updateDatabase(compact('server', 'channel', 'nick'), compact('type', 'message'))) {
           $logger->error($e->getMessage());
        }
    }

    /**
     * Populate channels with names on join.
     *
     * @param \Phergie\Irc\Event\ServerEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processNames(ServerEventInterface $event, EventQueueInterface $queue)
    {
        $special = '\[\]\\`_\^\{\|\}';

        $server = strtolower($event->getConnection()->getServerHostname());
        $params = array_slice($event->getParams(), 2);
        $channel = array_shift($params);

        $this->getLogger()->debug('Adding names to channel', array('server' => $server, 'channel' => $channel));
        
        $names = (count($params) == 1) ? explode(' ', $params[0]) : $params;

        foreach (array_filter($names) as $name) {
            // Strip prefix characters
            $name = preg_replace("/^[^A-Za-z$special]+/", '', $name);

            $this->channels[$server][$channel][$name] = true;
        }
    }

    /**
     * For test suite
     */
    public function getChannelStore()
    {
        return $this->channels;
    }
}