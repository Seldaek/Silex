<?php

/*
 * This file is part of the Silex framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Silex\Provider;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Logger;
use Monolog\Handler;
use Monolog\ErrorHandler;
use Silex\Api\BootableProviderInterface;
use Symfony\Bridge\Monolog\Handler\DebugHandler;
use Symfony\Bridge\Monolog\Handler\FingersCrossed\NotFoundActivationStrategy;
use Silex\EventListener\LogListener;

/**
 * Monolog Provider.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class MonologServiceProvider implements ServiceProviderInterface, BootableProviderInterface
{
    public function register(Container $app)
    {
        $app['logger'] = function () use ($app) {
            return $app['monolog'];
        };

        if ($bridge = class_exists('Symfony\Bridge\Monolog\Logger')) {
            $app['monolog.handler.debug'] = function () use ($app) {
                $level = MonologServiceProvider::translateLevel($app['monolog.level']);

                return new DebugHandler($level);
            };

            $app['monolog.not_found_activation_strategy'] = function () use ($app) {
                return new NotFoundActivationStrategy($app['request_stack'], array('^/'), $app['monolog.level']);
            };
        }

        $app['monolog.logger.class'] = $bridge ? 'Symfony\Bridge\Monolog\Logger' : 'Monolog\Logger';

        $app['monolog'] = function ($app) {
            $log = new $app['monolog.logger.class']($app['monolog.name']);

            $handler = new Handler\GroupHandler($app['monolog.handlers']);
            if (isset($app['monolog.not_found_activation_strategy'])) {
                $handler = new Handler\FingersCrossedHandler($handler, $app['monolog.not_found_activation_strategy']);
            }

            $log->pushHandler($handler);

            return $log;
        };

        $app['monolog.formatter'] = function () {
            return new LineFormatter();
        };

        $app['monolog.handler'] = function () use ($app) {
            $level = MonologServiceProvider::translateLevel($app['monolog.level']);

            $handler = new Handler\StreamHandler($app['monolog.logfile'], $level, $app['monolog.bubble'], $app['monolog.permission']);
            $handler->setFormatter($app['monolog.formatter']);

            return $handler;
        };

        $app['monolog.handlers'] = function () use ($app) {
            $handlers = array();

            if ($app['monolog.logfile']) {
                $handlers[] = $app['monolog.handler'];
            }

            if ($app['debug'] && isset($app['monolog.handler.debug'])) {
                $handlers[] = $app['monolog.handler.debug'];
            }

            return $handlers;
        };

        $app['monolog.level'] = function () {
            return Logger::DEBUG;
        };

        $app['monolog.listener'] = function () use ($app) {
            return new LogListener($app['logger'], $app['monolog.exception.logger_filter']);
        };

        $app['monolog.name'] = 'app';
        $app['monolog.bubble'] = true;
        $app['monolog.permission'] = null;
        $app['monolog.exception.logger_filter'] = null;
        $app['monolog.logfile'] = null;
    }

    public function boot(Container $app)
    {
        ErrorHandler::register($app['monolog']);

        if (isset($app['monolog.listener'])) {
            $app['dispatcher']->addSubscriber($app['monolog.listener']);
        }
    }

    public static function translateLevel($name)
    {
        // level is already translated to logger constant, return as-is
        if (is_int($name)) {
            return $name;
        }

        $levels = Logger::getLevels();
        $upper = strtoupper($name);

        if (!isset($levels[$upper])) {
            throw new \InvalidArgumentException("Provided logging level '$name' does not exist. Must be a valid monolog logging level.");
        }

        return $levels[$upper];
    }
}
