<?php

class_alias('Muffin\Bot\Notify\Plugin', 'Notify');
class_alias('Muffin\Bot\Remember\Plugin', 'Remember');
class_alias('Muffin\Bot\Seen\Plugin', 'Seen');
class_alias('Phergie\Irc\Plugin\React\AutoJoin\Plugin', 'AutoJoin');
class_alias('Phergie\Irc\Plugin\React\Command\Plugin', 'Command');
class_alias('Phergie\Irc\Plugin\React\CommandHelp\Plugin', 'CommandHelp');
class_alias('Phergie\Irc\Plugin\React\EventFilter\Plugin', 'EventFilter');
class_alias('Phergie\Irc\Plugin\React\FeedTicker\Plugin', 'FeedTicker');
class_alias('Phergie\Irc\Plugin\React\JoinPart\Plugin', 'JoinPart');
class_alias('Phergie\Irc\Plugin\React\NickServ\Plugin', 'NickServ');
class_alias('Phergie\Irc\Plugin\React\Pong\Plugin', 'Pong');
class_alias('Phergie\Irc\Plugin\React\Quit\Plugin', 'Quit');
class_alias('WyriHaximus\Phergie\Plugin\Dns\Plugin', 'Dns');

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use josegonzalez\Dotenv\Loader as SensitiveDataLoader;
use Phergie\Irc\Connection;
use React\Dns\Resolver\Resolver;
use Phergie\Irc\Plugin\React\EventFilter as Filter;

SensitiveDataLoader::load([
    'filepath' => '.env',
    'expect' => [
        'NICKSERV_PASSWORD',
    ],
    'toEnv' => true,
    'raiseExceptions' => false,
]);

Configure::write([
    'Channels' => [
        '#CakePHP' => '',
        '#FluxCTRL' => '',
        '#FriendsOfCake' => '',
    ],
    'Datasources' => [
        'default' => [
            'className' => 'Cake\Database\Connection',
            'driver' => 'Cake\Database\Driver\Sqlite',
            'database' => ':memory:',
        ]
    ],
    'Networks' => [
        'freenode' => [
            'serverHostname' => 'irc.freenode.net',
            'username' => 'bot',
            'realname' => 'http://usemuffin.com',
            'nickname' => '[muffin]',
        ],
    ],
    'Plugins' => [
        'Pong',
        'Dns',
        'CommandHelp',
        'Seen',
        'NickServ' => [
            'password' => $_ENV['NICKSERV_PASSWORD'],
        ],
    ],
]);

ConnectionManager::config(Configure::consume('Datasources'));

$connections = Configure::read('Networks');
$connections = array_map(function ($config) { return new Connection($config); }, $connections);

$plugins = Configure::read('Plugins') + [
    'Command' => [
        'prefix' => '~',
        'nick' => true,
    ],
    'AutoJoin' => [
        'channels' => array_keys(call_user_func_array(['Cake\Core\Configure', 'read'], ['Channels'])),
        'keys' => call_user_func_array(['Cake\Core\Configure', 'read'], ['Channels']),
    ],
    'EventFilter' => [
        'filter' => new Filter\UserFilter([
            'jadb!~jadb@unaffiliated\\/jadb*',
            'ADmad!~ADmad@unaffiliated\\/admad*',
        ]),
        'plugins' => [
            new JoinPart(),
            new Notify(),
            new Remember(),
            new Quit(['message' => 'master %s ordered me to']),
        ]
    ]
];

foreach ($plugins as $plugin => $config) {
    unset($plugins[$plugin]);
    if (is_numeric($plugin)) {
        $plugins[] = is_object($config) ? $config : new $config();
        continue;
    }
    $plugins[] = new $plugin($config);
}

return compact('connections', 'plugins');
