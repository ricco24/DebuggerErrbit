<?php

namespace DebuggerErrbit;

use Nette\Diagnostics\Debugger as NDebugger,
	Errbit;

class Debugger
{	
	/** @var bool					Send errors to errbit */
	private static $sendErrors;
	
	/** @var bool					Is console mode */
	private static $consoleMode;
	
	/** @var  */
	private static $logTable;
	
	/** @var string					Remote address of request */
	private static $remoteAddress;
	
	/** @var const					Nette\Diagnostics\Debugger constants */
	const DEBUG = 'debug',
		INFO = 'info',
		WARNING = 'warning',
		ERROR = 'error',
		CRITICAL = 'critical';
	
	/** @var array					Allowed severity for error handler */
	public static $severity = array(
		E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR, E_DEPRECATED, E_USER_DEPRECATED
	);
	
	/** @var array					List of unrecoverable errors */
	private static $unrecoverable = array(
		E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE
	);
	
	/** @var array					Ignored exceptions */
	private static $ignoredExceptions = array(
		'Nette\Application\InvalidPresenterException',	// HTTP 404 
		'Nette\Application\BadRequestException'			// HTTP 404
	);
	
	/**
	 * Initialization
	 * @param \Nette\DI\Container $container
	 */
	public static function init($container, $sendErrors = true) {
		self::$sendErrors = $sendErrors;
		self::$consoleMode = $container->parameters['consoleMode'];
		self::$remoteAddress = $container->httpRequest->getRemoteAddress();
		
		// Init errbit
		Errbit::instance()->configure(array(
				'api_key' => $container->parameters['errbit']['api_key'],
				'host' => $container->parameters['errbit']['host'],
				'port' => $container->parameters['errbit']['port'],										
				'secure' => $container->parameters['errbit']['secure'],
				'environment_name' => $container->parameters['errbit']['environment']
			))->start(array());
		
		// Register handlers
		register_shutdown_function(array(__CLASS__, '_shutdownHandler'));
		set_exception_handler(array(__CLASS__, '_exceptionHandler'));
		set_error_handler(array(__CLASS__, '_errorHandler'));
	}
	
	/** 
	 * Setup log table
	 * 
	 * @param \Nette\Database\Table\Selection $logTable
	 */
	public static function setLogTable(\Nette\Database\Table\Selection $logTable) {
		self::$logTable = $logTable;
	}
	
	/**
	 * Wrapper for Debugger::log() method
	 * @param string $message
	 * @param int $priority
	 */
	public static function log($message, $priority = NDebugger::INFO) {
		NDebugger::log($message, $priority);
		
		// Log to errbit
		if(!($message instanceof \Exception)) {
			$message = new \Exception($message);
		}		
		
		if(self::$sendErrors && ($priority == NDebugger::ERROR)) {
			Errbit::instance()->notify($message);
		}
	}
	
	/**
	 * Log to database
	 * @param string $flag
	 * @param string $description
	 * @param mixed $data
	 */
	public static function dbLog($flag, $method, $description, $data = null) {	
		if(!self::$logTable) {
			NDebugger::log('No log table given', NDebugger::ERROR);
		}
		
		try {
			self::$logTable->insert(array(
				'data' => $data ? serialize($data) : null,
				'description' => $description,
				'method' => $method,
				'flag' => $flag,
				'ip' => self::$remoteAddress,
				'created' => new \DateTime('now')
			)); 
		} catch(\Exception $e) {
			self::log($e, self::ERROR);
		}
		
	}
	
	/**
	 * Log message to cli if console mode is set
	 * @param type $msg
	 */
	public static function consoleLog($msg) {
		if(self::$consoleMode) {
			echo $msg;
		}
	}

	/**
	 * Shutdown handler for log fatal errors
	 */
	public static function _shutdownHandler() {	
		$error = error_get_last();
		
		if (self::$sendErrors && (in_array($error['type'], self::$unrecoverable))) {	
			Errbit::instance()->notify(new \Errbit_Errors_Fatal($error['message'], $error['file'], $error['line']));
		}
	}

	/**
	 * Log exception
	 * @param \Exception $exception
	 * @param boolean $shutdown
	 */
	public static function _exceptionHandler(\Exception $exception, $shutdown = FALSE) {	
		if(self::$sendErrors) {			
			$ignore = false;
			foreach(self::$ignoredExceptions as $ignoredException) {
				if($exception instanceof $ignoredException) {
					$ignore = true;
				}
			}
			if(!$ignore) {
				Errbit::instance()->notify(new \Exception($exception));	
			}
		}
		
		// Log by nette debugger
		NDebugger::_exceptionHandler($exception, $shutdown);
	}

	/**
	 * Log error
	 * @param int $severity
	 * @param string $message
	 * @param string $file
	 * @param int $line
	 * @param type $context
	 */
	public static function _errorHandler($severity, $message, $file, $line, $context) {
		// Check if we want to log this severity to errbit
		if(in_array(E_ALL, self::$severity) || in_array($severity, self::$severity)) {
			switch ($severity) {
				case E_NOTICE:
				case E_USER_NOTICE:
					$exception = new \Errbit_Errors_Notice($message, $file, $line, debug_backtrace());
					break;

				case E_WARNING:
				case E_USER_WARNING:
					$exception = new \Errbit_Errors_Warning($message, $file, $line, debug_backtrace());
					break;

				case E_ERROR:
				case E_USER_ERROR:
				default:
					$exception = new \Errbit_Errors_Error($message, $file, $line, debug_backtrace());
			}
				
			if(self::$sendErrors) {
				Errbit::instance()->notify($exception);
			}
		}
		
		// Log by nette debugger
		NDebugger::_errorHandler($severity, $message, $file, $line, $context);
	}
	
	/***************************** TABLE IMPORT *******************************/
	
	// @TODO: add import database table code
	public static function createDbTable($table) {
		// phinx table
		$table->addColumn('data', 'text')
			->addColumn('description', 'text')
			->addColumn('method', 'string')
			->addColumn('created', 'datetime')
			->addColumn('flag', 'string')
			->addColumn('ip', 'string')
			->addIndex('created')
			->save();
	}
}
