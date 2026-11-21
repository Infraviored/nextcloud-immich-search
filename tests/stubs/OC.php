<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Server-internal interfaces that the public OCP stubs extend. Only what the tests touch.

namespace OC\Hooks;

interface Emitter {
	public function listen($scope, $method, callable $callback);
	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}

namespace Symfony\Component\Console\Command;

if (!class_exists(Command::class)) {
	class Command {
		public const SUCCESS = 0;
		public const FAILURE = 1;
		private string $name = '';
		private array $aliases = [];
		private string $description = '';
		private array $arguments = [];
		private array $options = [];
		public function __construct(?string $name = null) {
			if ($name !== null) {
				$this->name = $name;
			}
			$this->configure();
		}
		protected function configure(): void {
		}
		public function setName(string $name): static {
			$this->name = $name;
			return $this;
		}
		public function getName(): string {
			return $this->name;
		}
		public function setAliases(array $aliases): static {
			$this->aliases = $aliases;
			return $this;
		}
		public function setDescription(string $description): static {
			$this->description = $description;
			return $this;
		}
		public function addArgument(string $name, ?int $mode = null, string $description = '', mixed $default = null): static {
			$this->arguments[$name] = $default;
			return $this;
		}
		public function addOption(string $name, ?string $shortcut = null, ?int $mode = null, string $description = '', mixed $default = null): static {
			$this->options[$name] = $default;
			return $this;
		}
		public function run(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int {
			return $this->execute($input, $output);
		}
		protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int {
			return self::SUCCESS;
		}
	}
}

namespace Symfony\Component\Console\Input;

if (!class_exists(InputArgument::class)) {
	class InputArgument {
		public const REQUIRED = 1;
		public const OPTIONAL = 2;
		public const IS_ARRAY = 4;
	}
}

if (!class_exists(InputOption::class)) {
	class InputOption {
		public const VALUE_NONE = 1;
		public const VALUE_REQUIRED = 2;
		public const VALUE_OPTIONAL = 4;
	}
}

if (!interface_exists(InputInterface::class)) {
	interface InputInterface {
		public function getArgument(string $name): mixed;
		public function getOption(string $name): mixed;
	}
}

if (!class_exists(ArrayInput::class)) {
	class ArrayInput implements InputInterface {
		public function __construct(
			private array $parameters,
		) {
		}
		public function getArgument(string $name): mixed {
			return $this->parameters[$name] ?? null;
		}
		public function getOption(string $name): mixed {
			$key = ltrim($name, '-');
			return $this->parameters['--' . $key] ?? $this->parameters[$key] ?? null;
		}
	}
}

namespace Symfony\Component\Console\Output;

if (!interface_exists(OutputInterface::class)) {
	interface OutputInterface {
		public function write(string|iterable $messages, bool $newline = false, int $options = 0): void;
		public function writeln(string|iterable $messages, int $options = 0): void;
	}
}

if (!class_exists(BufferedOutput::class)) {
	class BufferedOutput implements OutputInterface {
		private string $buffer = '';
		public function write(string|iterable $messages, bool $newline = false, int $options = 0): void {
			$msg = is_iterable($messages) ? implode('', (array)$messages) : (string)$messages;
			$this->buffer .= $msg . ($newline ? "\n" : '');
		}
		public function writeln(string|iterable $messages, int $options = 0): void {
			$this->write($messages, true, $options);
		}
		public function fetch(): string {
			$out = $this->buffer;
			$this->buffer = '';
			return $out;
		}
	}
}

namespace Symfony\Component\Console\Tester;

if (!class_exists(CommandTester::class)) {
	class CommandTester {
		private int $statusCode = 0;
		private string $display = '';
		public function __construct(
			private \Symfony\Component\Console\Command\Command $command,
		) {
		}
		public function execute(array $input, array $options = []): int {
			$output = new \Symfony\Component\Console\Output\BufferedOutput();
			$in = new \Symfony\Component\Console\Input\ArrayInput($input);
			$ref = new \ReflectionMethod($this->command, 'execute');
			$ref->setAccessible(true);
			$this->statusCode = (int)$ref->invoke($this->command, $in, $output);
			$this->display = $output->fetch();
			return $this->statusCode;
		}
		public function getStatusCode(): int {
			return $this->statusCode;
		}
		public function getDisplay(): string {
			return $this->display;
		}
	}
}
