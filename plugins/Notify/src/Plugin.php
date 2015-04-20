<?php
namespace Muffin\Bot\Notify;

use Cake\Database\Schema\Table as Schema;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Bot\React\EventQueueInterface;
use Phergie\Irc\Plugin\React\Command\CommandEvent;

class Plugin extends AbstractPlugin
{
    static public $tableName = 'bot_notifications';

    protected $command = 'notify';

    protected $connection = 'default';

    protected $table;

    public function __construct(array $config = [])
    {
        if (isset($config['command'])) {
            $this->command = $config['command'];
        }

        if (isset($config['connection'])) {
            $this->connection = $config['connection'];
        }

        $connection = ConnectionManager::get($this->connection);
        $tables = $connection->schemaCollection()->listTables();
        $columns = [
            'id' => ['type' => 'integer', 'autoIncrement' => true],
            'nick' => 'string',
            'target' => 'string',
            'server' => 'string',
            'channel' => 'string',
            'message' => 'text',
            'created' => 'datetime',
        ];

        if (!in_array(static::$tableName, $tables)) {
            $schema = new Schema(static::$tableName, $columns);
            $schema->addConstraint('primary', [
                'type' => 'primary',
                'columns' => ['id'],
            ]);
            $schema->addIndex('idx_to', [
                'type' => 'index',
                'columns' => ['target'],
            ]);
            $connection->query(implode(';', $schema->createSql($connection)));
        }

        $this->table = TableRegistry::get('Notifications', [
            'connection' => $connection,
            'table' => static::$tableName,
        ]);
        $this->table->primaryKey(['id']);
    }

    public function getSubscribedEvents()
    {
        if (!$this->connection) {
            return [];
        }

        return [
            'command.notify' => 'handleCommand',
            'command.notify.help' => 'handleCommandHelp',
            'irc.received.join' => 'processJoin',
        ];
    }

    public function handleCommand(CommandEvent $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();
        $source = $event->getSource();
        $nick = $event->getNick();
        $server = strtolower($event->getConnection()->getServerHostname());
        $channel = $event->getParams();

        if ($nick === null || $channel === null) {
            $logger->debug('Command request not in channel, ignoring');
        }

        $params = $event->getCustomParams();
        if (empty($params)) {
            $this->handleCommandHelp($event, $queue);
            return;
        }

        list($target, $message) = explode(' that ', implode(' ', $params));
        if (empty($target) || empty($message)) {
            $this->handleCommandHelp($event, $queue);
            return;
        }

        $data = compact(
            'nick',
            'target',
            'server',
            'channel',
            'message'
        );

        $notification = $this->table->newEntity($data + ['created' => date('Y-m-d H:i:s')]);

        if (!$this->table->save($notification)) {
            $logger->debug('Failed to save notification: ' . json_encode($data));
            $queue->ircPrivmsg($source, $by . ' sorry, encountered a problem and notified my masters.');
            return;
        }

        $queue->ircPrivmsg($source, 'Thanks! I will make sure to notify ' . $target . ' of that.');
    }

    public function handleCommandHelp(CommandEvent $event, EventQueueInterface $queue)
    {
        $queue->ircPrivmsg($event->getSource(), "\x02Usage:\x02 remember <keyword> is <answer>");
    }

    public function processJoin(UserEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();
        $source = $event->getSource();
        $target = $event->getNick();
        $server = strtolower($event->getConnection()->getServerHostname());
        $channel = $event->getParams()['channels'];

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

        $query = $this->table->find()
            ->select(['id', 'nick', 'message'])
            ->where(compact('target'));

        if (!$query->count()) {
            return;
        }

        foreach ($query as $notification) {
            $queue->ircPrivmsg($source, sprintf(
                '%s, a message from %s: %s',
                $target,
                $notification->nick,
                $notification->message
            ));
        }

        $this->table->deleteAll(compact('target'));
    }
}
