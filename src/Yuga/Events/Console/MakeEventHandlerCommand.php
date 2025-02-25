<?php
namespace Yuga\Events\Console;

use Yuga\Support\Str;
use Yuga\Console\Command;
use Nette\PhpGenerator\PhpFile;
use Yuga\Application\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class MakeEventHandlerCommand extends Command
{
    protected $name = 'event:handler';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make an Event Handler Class that the event will trigger';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $event = $this->input->getOption('event');
        $method = $this->input->getOption('method');
        $handler = $this->argument('name');

        $eventName = $this->processEventName($event);
        $this->createDirectories();
        $this->processHandler($handler, $method, $eventName, $event);
        $this->info('Event Handler created successfully.');
    }

    /**
     * Process the name of an event given
     * 
     * @param string $event
     * 
     * @return string $eventName
     */
    protected function processEventName($event)
    {
        $eventName = $event;
        $eventClass = env('APP_NAMESPACE', 'App'). '\\Events\\' . $event;
        if (class_exists($eventClass)) {
            $app = Application::getInstance();
            $eventObject = $app->resolve($eventClass);
            $eventName = $eventObject->getName();
        }
        return $eventName;
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the EventHandler class'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['event', null, InputOption::VALUE_OPTIONAL, 'The name of the Event class or just the event name.', 'yuga.auto.events'],
            ['method', null, InputOption::VALUE_OPTIONAL, 'The name of the method or function in the handler class to associate with the event.', 'handle'],
        ];
    }

    /**
     * Create the directories for the files.
     *
     * @return void
     */
    protected function createDirectories()
    {
        if (!is_dir($directory = path('app/Handlers'))) {
            mkdir($directory, 0755, true);
        }
    }

    /**
     * Process an event handler
     * 
     * @param string $handler
     * @param string $method
     * @param string $eventName
     * 
     * @return void
     */
    protected function processHandler($handler, $method, $eventName, $event)
    {
        file_put_contents(
            path('app/Handlers/'.trim($handler).'.php'),
            // $this->compileHandlerTemp(trim($handler))
            $this->generateHandler($handler, $method, $eventName, $event)
        );
        file_put_contents(
            path('config/AppEvents.php'),
            $this->processAppConfigFiles($handler, $method, $eventName)
        );
    }

    /**
     * Generate handler logic
     * 
     * @param string $handler
     * @param string $method
     * @param string $eventName
     * 
     * @return void
     */
    protected function generateHandler($handler, $method, $eventName, $event)
    {
        $file = new PhpFile;
        $file->addComment('This file was auto-generated.');

        $namespace = $file->addNamespace(env('APP_NAMESPACE', 'App'). '\\Handlers');
        $namespace->addUse('Yuga\EventHandlers\HandlerInterface');
        if ($event != 'yuga.auto.events') {
            if (\class_exists(env('APP_NAMESPACE', 'App') . '\\Events\\' . $event))
                $namespace->addUse(env('APP_NAMESPACE', 'App') . '\\Events\\' . $event);
        }

        $class = $namespace->addClass(trim($handler));
        $class->addImplement('Yuga\EventHandlers\HandlerInterface');

        $classMethod = $class->addMethod('handle')->setBody('return null;');
        $classMethod->addComment('Event Handler Logic in this method');
        $classMethod->addComment('@param \Yuga\Events\Dispatcher $event');
        $classMethod->addComment('@return mixed');
        $paramether = $classMethod->addParameter('event');
        
        if (trim($method) != 'handle') {
            $classMethod = $class->addMethod(trim($method))->setBody('return null;');
            $classMethod->addComment('Your Event Handler Logic in this method');
            if ($event != 'yuga.auto.events') {
                if (\class_exists(env('APP_NAMESPACE', 'App') . '\\Events\\' . $event))
                    $classMethod->addComment('@param ' . env('APP_NAMESPACE', 'App') . '\\Events\\' . $event . ' $event');
            } else {
                $classMethod->addComment('@param \Yuga\Events\Dispatcher $event');
            }
            
            $classMethod->addComment('@return mixed');
            $paramether = $classMethod->addParameter('event');
            if ($event != 'yuga.auto.events') {
                if (\class_exists(env('APP_NAMESPACE', 'App') . '\\Events\\' . $event))
                    $paramether->setType(env('APP_NAMESPACE', 'App') . '\\Events\\' . $event);
            } else {
                $paramether->setType('\Yuga\Events\Dispatcher');
            }
        }

        return $file;
    }

    /**
     * Compile strings to produce meaning php
     * 
     * @param string $handlerName
     * 
     * @return string
     */
    protected function compileHandlerTemp($handlerName)
    {
        $handler = str_replace('{namespace}', env('APP_NAMESPACE', 'App'), file_get_contents(__DIR__.'/temps/Handler.temp'));
        return str_replace('{class}', $handlerName, $handler);
    }

    /**
     * Process config files for automatic registration of events and handler attachement
     * 
     * @param string $handler
     * @param string $method
     * @param string $eventName
     * 
     * @return void
     */
    protected function processAppConfigFiles($handler, $method, $eventName)
    {
        $events = [];
        if (\file_exists(path('config/AppEvents.php'))) {
            $events = require path('config/AppEvents.php');
        }
        $sentHandler = env('APP_NAMESPACE', 'App') . '\\Handlers\\' . $handler;
        $sentEvent = $eventName;

        if (in_array($eventName, array_keys($events))) {
            $handlers = [];
            if (is_string($events[$eventName])) {
                $handlers[] = $events[$eventName];

                if (!in_array($sentHandler, $handlers)) {
                    $handlers[] = $sentHandler;
                }
            } elseif (is_array($events[$eventName])) {
                if (!in_array($sentHandler, $events[$eventName])) {
                    $events[$eventName][] = $sentHandler;
                }
                $handlers = $events[$eventName];
            }
            $events[$eventName] = $handlers;
        } else {
            $events[$eventName][] = $sentHandler;
        }

        $generatedEvents = '[';
        foreach ($events as $event => $handlers) {
            $generatedEvents .= "\n\t'" . $event . "' => [";
            if (is_array($handlers)) {
                foreach ($handlers as $handler) {
                    if ($method != 'handle' && $sentHandler == $handler && $sentEvent == $eventName) {
                        $generatedEvents .= "\n\t\t['" . $handler . "', '" . $method . "'],";
                    } else {
                        if (is_array($handler)) {
                            $generatedEvents .= "\n\t\t['" . $handler[0] . "', '" . $handler[1] . "'],";
                        } else {
                            $generatedEvents .= "\n\t\t" . $handler . "::class,";
                        }
                    }
                }
            } else {
                $generatedEvents .= "\n\t\t" . $handlers . "::class,";
            }
            $generatedEvents .= "\n\t],";
        }
        $generatedEvents .= "\n];";
        

        $handlers = str_replace(
            '{handlers}',
            $generatedEvents,
            file_get_contents(__DIR__ . '/temps/config.temp')
        );

        return $handlers;
    }
}