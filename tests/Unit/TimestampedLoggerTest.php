<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Logging\TimestampedLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

describe('TimestampedLogger', function () {

    it('prepends a timestamp matching the configured format', function () {
        $captured = null;
        $inner = new class($captured) implements LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public function __construct(private &$captured) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->captured = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $logger = new TimestampedLogger($inner, 'Y-m-d');
        $logger->info('hello', ['k' => 'v']);

        $today = (new DateTimeImmutable())->format('Y-m-d');
        expect($captured['level'])->toBe(LogLevel::INFO);
        expect($captured['message'])->toBe("[{$today}] hello");
        expect($captured['context'])->toBe(['k' => 'v']);
    });

    it('passes through every PSR-3 level', function () {
        $levels = [];
        $inner = new class($levels) implements LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public function __construct(private array &$levels) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->levels[] = $level;
            }
        };

        $logger = new TimestampedLogger($inner);
        $logger->emergency('e');
        $logger->alert('a');
        $logger->critical('c');
        $logger->error('e');
        $logger->warning('w');
        $logger->notice('n');
        $logger->info('i');
        $logger->debug('d');

        expect($levels)->toBe([
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR,
            LogLevel::WARNING,
            LogLevel::NOTICE,
            LogLevel::INFO,
            LogLevel::DEBUG,
        ]);
    });

    it('default format includes millisecond precision', function () {
        $message = null;
        $inner = new class($message) implements LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public function __construct(private &$message) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->message = (string) $message;
            }
        };

        $logger = new TimestampedLogger($inner);
        $logger->info('boom');

        // Format: [YYYY-MM-DD HH:MM:SS.mmm] boom
        expect($message)->toMatch('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\] boom$/');
    });
});
