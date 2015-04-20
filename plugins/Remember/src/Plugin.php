<?php
namespace Muffin\Bot\Remember;

use Cake\Database\Schema\Table as Schema;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Bot\React\EventQueueInterface;
use Phergie\Irc\Plugin\React\Command\CommandEvent;

class Plugin extends AbstractPlugin
{
    static public $tableName = 'bot_answers';

    protected $command = 'remember';

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
            'keyword' => 'string',
            'message' => 'text',
            'nick' => 'string',
            'created' => 'datetime',
        ];

        if (!in_array(static::$tableName, $tables)) {
            $schema = new Schema(static::$tableName, $columns);
            $schema->addConstraint('primary', [
                'type' => 'primary',
                'columns' => ['keyword'],
            ]);
            $connection->query(implode(';', $schema->createSql($connection)));
        }

        $this->table = TableRegistry::get('Answers', [
            'connection' => $connection,
            'table' => static::$tableName,
        ]);
        $this->table->primaryKey(['keyword']);
    }

    public function getSubscribedEvents()
    {
        if (!$this->connection) {
            return [];
        }

        return [
            'command.remember' => 'handleCommand',
            'command.remember.help' => 'handleCommandHelp',
        ];
    }

    public function handleCommand(CommandEvent $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();
        $source = $event->getSource();
        $nick = $event->getNick();

        if ($nick === null) {
            $logger->debug('Command request not in channel, ignoring');
        }

        $params = $event->getCustomParams();
        if (empty($params)) {
            $this->handleCommandHelp($event, $queue);
            return;
        }

        list($keyword, $message) = explode(' is ', implode(' ', $params));
        if (empty($keyword) || empty($message)) {
            $this->handleCommandHelp($event, $queue);
            return;
        }

        if ($this->table->exists(compact('keyword'))) {
            $queue->ircPrivmsg($source, $nick . ' sorry, but I already have an answer for that.');
            return;
        }

        $data = compact('keyword', 'message', 'nick');
        $answer = $this->table->newEntity($data + ['created' => date('Y-m-d H:i:s')]);

        if (!$this->table->save($answer)) {
            $logger->debug('Failed to save answer: ' . json_encode($data));
            $queue->ircPrivmsg($source, $nick . ' sorry, encountered a problem and notified my masters.');
            return;
        }

        $queue->ircPrivmsg($source, 'Thanks! I will make sure to remember that.');
    }

    public function handleCommandHelp(CommandEvent $event, EventQueueInterface $queue)
    {
        $queue->ircPrivmsg($event->getSource(), "\x02Usage:\x02 remember <keyword> is <answer>");
    }
}
