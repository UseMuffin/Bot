<?php

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
        // 'GITHUB_USER',
        // 'GITHUB_TOKEN',
        'NICKSERV_PASSWORD',
        // 'DATABASE_DEFAULT_HOST',
        // 'DATABASE_DEFAULT_USER',
        // 'DATABASE_DEFAULT_PASS',
        // 'DATABASE_DEFAULT_NAME',
    ],
    'toEnv' => true,
    'raiseExceptions' => false,
]);

Configure::write([
    'Channels' => [
        '#FluxCTRL' => '',
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
        'Command',
        'CommandHelp',
        'Seen',
        'NickServ' => [
            'password' => $_ENV['NICKSERV_PASSWORD'],
        ],
        'EventFilter' => [
            'filter' => new Filter\UserFilter([
                'jadb!~jadb@*',
                'ADmad!*@*',
                'jose_zap!*@*'
            ]),
            'plugins' => [
                new JoinPart(),
                new Quit(['message' => 'master %s ordered me to']),
            ]
        ]
    ],
]);

ConnectionManager::config(Configure::consume('Datasources'));

$connections = Configure::consume('Networks');
$connections = array_map(function ($config) { return new Connection($config); }, $connections);

$plugins = Configure::consume('Plugins') + [
    'AutoJoin' => [
        'channels' => array_keys(call_user_func_array(['Cake\Core\Configure', 'read'], ['Channels'])),
        'keys' => call_user_func_array(['Cake\Core\Configure', 'read'], ['Channels']),
    ],
];

foreach ($plugins as $plugin => $config) {
    unset($plugins[$plugin]);
    if (is_numeric($plugin)) {
        $plugins[] = new $config();
        continue;
    }
    $plugins[] = new $plugin($config);
}

return compact('connections', 'plugins');
