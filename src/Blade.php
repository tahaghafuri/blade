<?php

namespace TAG\Blade;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\FileViewFinder;
use Illuminate\View\Factory;

class Blade {

	/**
	 * Array containing paths where to look for blade files
	 * @var array<string>
	 */
	protected array $viewPaths;

	/**
	 * Location where to store cached views
	 * @var string
	 */
	protected string $cachePath;

	/**
	 * @var Container
	 */
	protected Container $container;

	/**
	 * @var Factory
	 */
	protected Factory $instance;

	/**
	 * Initialize class
	 * @param string|array $viewPaths
	 * @param string $cachePath
	 * @param Illuminate\Events\Dispatcher|null $events
	 * @throws \InvalidArgumentException
	 */
	function __construct($viewPaths = [], string $cachePath = null, Dispatcher $events = null) {
		if ($cachePath === null) {
			throw new \InvalidArgumentException('Cache path must be provided');
		}

		$this->container = new Container;
		$this->viewPaths = (array) $viewPaths;
		$this->cachePath = rtrim($cachePath, '/\\');

		$this->registerFilesystem();

		$this->registerEvents($events ?: new Dispatcher);

		$this->registerEngineResolver();

		$this->registerViewFinder();

		$this->instance = $this->registerFactory();
	}

	public function view(): Factory
	{
		return $this->instance;
	}

	protected function registerFilesystem(): void
	{
		$this->container->singleton('files', function() {
			return new Filesystem;
		});
	}

	protected function registerEvents(Dispatcher $events): void
	{
		$this->container->singleton('events', function() use ($events)
		{
			return $events;
		});
	}

	/**
	 * Register the engine resolver instance.
	 *
	 * @return void
	 */
	public function registerEngineResolver(): void
	{
		$this->container->singleton('view.engine.resolver', function($app) {
			$resolver = new EngineResolver;

			foreach (['php', 'blade'] as $engine) {
				$method = 'register' . ucfirst($engine) . 'Engine';
				$this->{$method}($resolver);
			}

			return $resolver;
		});
	}

	/**
	 * Register the PHP engine implementation.
	 *
	 * @param  \Illuminate\View\Engines\EngineResolver  $resolver
	 * @return void
	 */
	public function registerPhpEngine($resolver)
	{
		$resolver->register('php', function() { return new PhpEngine; });
	}

	/**
	 * Register the Blade engine implementation.
	 *
	 * @param  \Illuminate\View\Engines\EngineResolver  $resolver
	 * @return void
	 */
	public function registerBladeEngine($resolver)
	{
		$me = $this;
		$app = $this->container;

		// The Compiler engine requires an instance of the CompilerInterface, which in
		// this case will be the Blade compiler, so we'll first create the compiler
		// instance to pass into the engine so it can compile the views properly.
		$this->container->singleton('blade.compiler', function($app) use ($me)
		{
			$cache = $me->cachePath;

			return new BladeCompiler($app['files'], $cache);
		});

		$resolver->register('blade', function() use ($app)
		{
			return new CompilerEngine($app['blade.compiler'], $app['files']);
		});
	}

	/**
	 * Register the view finder implementation.
	 *
	 * @return void
	 */
	public function registerViewFinder()
	{
		$me = $this;
		$this->container->singleton('view.finder', function($app) use ($me)
		{
			$paths = $me->viewPaths;

			return new FileViewFinder($app['files'], $paths);
		});
	}

	/**
	 * Register the view environment.
	 *
	 * @return Illuminate\View\Factory
	 */
	public function registerFactory()
	{
		// Next we need to grab the engine resolver instance that will be used by the
		// environment. The resolver will be used by an environment to get each of
		// the various engine implementations such as plain PHP or Blade engine.
		$resolver = $this->container['view.engine.resolver'];

		$finder = $this->container['view.finder'];

		$env = new Factory($resolver, $finder, $this->container['events']);

		// We will also set the container instance on this view environment since the
		// view composers may be classes registered in the container, which allows
		// for great testable, flexible composers for the application developer.
		$env->setContainer($this->container);

		return $env;
	}

	public function getCompiler()
	{
		return $this->container['blade.compiler'];
	}
}

?>
