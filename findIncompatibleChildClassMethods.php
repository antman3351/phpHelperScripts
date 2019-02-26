<?php
/**
 * @author antonio
 */
class debug
{

	public static $level = 0;

	public static function out( ?string $msg, $level = 1 ): void
	{

		if ( static::$level >= $level ) {

			echo PHP_EOL, '<br>', $msg;
		}
	}
}

class FindIncompatibleChildClassMethods
{

	/** @var ClassInfo[] */
	protected $classes				 = [];
	protected $childToParenClasses	 = [];
	protected $startDir;
	protected $fileExtention;
	protected $fails		 = 0;
	protected $warnings		 = 0;
	protected $failedClass	 = [];

	public function __construct( string $startDir = '.',
		string $fileExtention = '\.php' )
	{
		$this->startDir		 = $startDir;
		$this->fileExtention = $fileExtention;
	}

	public function run()
	{
		$stopWatch = new StopWatch();
		$stopWatch->start();

		debug::out( null, 1 );
		debug::out( "======= START FINDING CLASSES =======", 1 );
		$this->getAllClasses();
		debug::out( "======= DONE FOUND " . count( $this->classes ) . " CLASSES ======= {$stopWatch->stop()->getTime()} s", 1 );

		debug::out( null, 1 );
		debug::out( "======= START SORTING CLASSES BY INHERITANCE =======", 1 );
		$this->sortParentChildClasses();
		debug::out( "======= DONE SORTING CLASSES BY INHERITANCE ======= {$stopWatch->stop()->getTime()} s", 1 );

		debug::out( null, 1 );
		debug::out( "======= START CHECKING METHODS =======", 1 );
		$this->checkMethods();
		debug::out( "======= DONE CHECKING METHODS ======= {$stopWatch->stop()->getTime()} s", 1 );

		$this->outputResults();
	}

	protected function getAllClasses()
	{
		$fileFinder = new FileFinder( $this->startDir, $this->fileExtention );

		foreach ( $fileFinder->list() as $fileName ) {

			debug::out( "trying: {$fileName}", 2 );

			$class	 = new ClassInfo();
			$isClass = $class->parseDataFromSource( $fileName );

			if ( $isClass ) {

				$this->classes[$class->getName()] = $class;
			}
		}

		return $this;
	}

	protected function sortParentChildClasses()
	{
		foreach ( $this->classes as $class ) {

			if ( ! $class->extendsAClass() ) {
				continue;
			}

			if ( ! isset( $this->classes[$class->getExtends()] ) ) {
				debug::out( "ERROR: Couldn't find parent class {$class->getExtends()} in {$class->getName()} !", 0 );
				continue;
			}

			debug::out( "{$class->getName()} extends {$class->getExtends()}", 2 );

			$class->setParent( $this->classes[$class->getExtends()] );
		}

		return $this;
	}

	protected function checkMethods()
	{
		foreach ( $this->classes as $class ) {



			$methods = $class->getMethods();
			/** @var Method[]  $methods */

			ob_start( function( $buffer ) use ( $class ) {
				return PHP_EOL . "<br> <b>{$class->getName()}</b>" . $buffer;
			});

			foreach ( $methods as $method ) {
				$fails		 = 0;
				$warnings	 = 0;

				$this->allMethodsTests($method, $fails, $warnings);

				if ( $class->extendsAClass() ) {

					$parentMethod = $this->findParentMethod( $method, $class );

					if ( $parentMethod !== null ) {

						$this->methodsOverloadedTests( $method, $parentMethod, $fails, $warnings );
					}

				}

				if ( $fails > 0 || $warnings > 0 ) {

					$this->failedClass[$class->getName()]	 = $class->getName();
					$this->fails							 = $this->fails + $fails;
					$this->warnings							 = $this->warnings + $warnings;
				}

				if ($fails > 0 || $warnings > 0 || debug::$level > 2 ) {
					ob_flush();
				}

				ob_clean();
			}

			ob_end_clean();
		}

		return $this;
	}


	/**
	 * Checks to be run only on overloaded classes
	 * @param Method $method
	 * @param Method $parentMethod
	 * @param int $fails
	 * @param int $warnings
	 * @return void
	 */
	protected function methodsOverloadedTests( Method $method, Method $parentMethod, &$fails, &$warnings ) : void
	{
		if ( ! $this->checkMethodVisibility( $method, $parentMethod ) ) {
			$fails ++;
		}

		if ( ! $this->checkMethodStaticissity( $method, $parentMethod ) ) {
			$fails ++;
		}

		$warnings = $warnings + $this->checkMethodCompatablity( $method, $parentMethod );

		return;
	}

	/**
	 * Checks to be run on every method of every class
	 * @param Method $method
	 * @param int $fails
	 * @param int $warnings
	 * @return void
	 */
	protected function allMethodsTests( Method $method, &$fails, &$warnings ) : void
	{
		$warnings = $warnings + $this->checkOnlyVariablesShouldBePassByRef( $method );

		return;
	}

	/**
	 * Checks only type-hinted scalar values are pass by reference
	 * @param Method $method
	 * @return int warnings count
	 */
	protected function checkOnlyVariablesShouldBePassByRef( Method $method ) : int
	{
		$validVariablesTypes = ['array', 'bool', 'float', 'int', 'string'];

		$warnings = 0;
		foreach ( $method->getParameters() as $parameter ){
			/** @var Parameter $parameter */
			if ( $parameter->getPassByRef() && ! empty( $parameter->getTypeHint() ) && ! in_array( $parameter->getTypeHint(), $validVariablesTypes) ) {

				$warnings ++;
				debug::out( "Method {$method->getName()} ({$parameter->getTypeHint()} {$parameter->getName()})  Only variables should be passed by reference [WARNING]", 0 );
			}
		}

		return $warnings;
	}

	/**
	 * Looks for a parent (overloaded) method
	 * @param Method $method
	 * @param ClassInfo $class
	 * @return \Method|null
	 */
	protected function findParentMethod( Method $method, ClassInfo $class ): ?Method
	{
		while ( ! empty( ( $parent = $class->getParent() ) ) ) {
			if ( $parent->hasMethod( $method->getName() ) ) {

				return $parent->getMethod( $method->getName() );
			}

			$class = $parent;
		}

		debug::out( "Method {$method->getName()} doesn't override a parent method [PASS]", 3 );
		return null;
	}

	protected function checkMethodVisibility( Method $childMethod, Method $parentMethod ): bool
	{
		$visiblity = [
			'public'	 => 1,
			'protected'	 => 2,
			'private'	 => 3,
		];

		if ( $parentMethod->getVisibility() === 'private' ) {

			debug::out( "Method {$childMethod->getName()} visiblity private, skipping checks [PASS]", 3 );
			return true;
		}

		if ( $visiblity[$childMethod->getVisibility()] > $visiblity[$parentMethod->getVisibility()] ) {

			debug::out( "Method {$childMethod->getName()} can't be more restrictive than parent's access {$parentMethod->getVisibility()} [FAIL]", 0 );
			return false;
		}

		debug::out( "Method {$childMethod->getName()} has the same visiblity [PASS]", 3 );
		return true;
	}

	protected function checkMethodStaticissity( Method $childMethod, Method $parentMethod ): bool
	{
		if ( $childMethod->isStatic() !== $parentMethod->isStatic() ) {

			debug::out( "Method {$childMethod->getName()} should " . ( $parentMethod->isStatic() ? '' : 'NOT') . " be static to match parent [FAIL]", 0 );
			return false;
		}

		debug::out( "Method {$childMethod->getName()} has the same static(ness?) [PASS]", 3 );
		return true;
	}

	protected function checkMethodCompatablity( Method $childMethod, Method $parentMethod ): int
	{

		$warnings = 0;

		debug::out( "<i>Method {$childMethod->getName()}</i>", 0);


		if ( $childMethod->getParameterCount() < $parentMethod->getParameterCount() ) {
if ($childMethod->getName() === 'operation'){
	var_dump([$childMethod,$parentMethod]);
}
			debug::out( "Has less parameters than parent [WARNING]", 0 );
			$warnings ++;
		}

		$childParameters	 = $childMethod->getParameters();
		$parentParameters	 = $parentMethod->getParameters();

		$childHasMoreParameters = $childMethod->getParameterCount() > $parentMethod->getParameterCount();

		for ( $i = 0, $len = $childMethod->getParameterCount(); $i < $len; $i ++ ) {

			$childParameter	= $childParameters[$i];
			$parentParameter = $parentParameters[$i] ?? null;

			if ( $childHasMoreParameters ) {

				$this->checkMethodParameterMoreInChild( $childParameter, $parentParameter, $warnings );
			}


			if ( $parentParameter !== null ) {

				$this->checkMethodParameterTypeHint( $childParameter, $parentParameter, $warnings );
				$this->checkMethodParametePassByRef( $childParameter, $parentParameter, $warnings );
			}

		}

		return $warnings;
	}

	protected function checkMethodParameterMoreInChild ( Parameter $childParameter, ? Parameter $parentParameter, &$warnings ) : void
	{
		if ( $parentParameter !== null ) {
			return;
		}

		if ( $childParameter->hasDefaultValue() ) {
			return;
		}

		debug::out( "Has more parameters {$childParameter->getName()} (without default value) than parent [WARNING]", 0 );
		$warnings ++;
	}


	protected function checkMethodParameterTypeHint ( Parameter $childParameter, Parameter $parentParameter, &$warnings ) : void
	{
		if ( $childParameter->getTypeHint() !== $parentParameter->getTypeHint() ) {

			debug::out( "Parameter ( {$childParameter->getTypeHint()} \${$childParameter->getName()} ) doesn't have the same type hint as parent ( {$parentParameter->getTypeHint()} \${$parentParameter->getName()} ) [WARNING]", 0 );
			$warnings ++;
		}

		return;
	}

	protected function checkMethodParametePassByRef ( Parameter $childParameter, Parameter $parentParameter, &$warnings ) : void
	{
		if ( $childParameter->getPassByRef() !== $parentParameter->getPassByRef() ) {

			debug::out( "Parameter ( {$childParameter->getTypeHint()} \${$childParameter->getName()} ) is pass by " . ( $childParameter->getPassByRef() ? 'reference' : 'value' )
				. " where parent is " . ( $parentParameter->getPassByRef() ? 'reference' : 'value' ) . " [WARNING]", 0 );
			$warnings ++;
		}

		return;
	}

	protected function outputResults()
	{
		debug::out( "Finised with Warnings: {$this->warnings}, Fails: {$this->fails}", 0 );

		if ( ! empty( $this->failedClass ) && debug::$level > 0 ) {
			debug::out( "Please check the following classes:", 0 );
			foreach ( $this->failedClass as $className ) {

				debug::out( $className, 0 );
			}
		}

		if ( debug::$level < 3 ) {
			debug::out( 'Try chaing the debug value to ' . (debug::$level + 1) . ' for more infomation.', 0 );
		}

		return $this;
	}
}

class ClassInfo
{

	const PASS_BY_REFERENCE	 = true;
	const PASS_BY_VALUE		 = false;

	protected $name		 = '';
	protected $methods	 = [];
	protected $extends	 = '';
	protected $parent;

	public function parseDataFromSource( string $filename ): bool
	{
		$source = file_get_contents( $filename );

		$classLine = $this->getClassLine( $source );

		if ( $classLine === null ) {
			return false;
		}

		$this->name		 = $this->extractName( $classLine );
		$this->extends	 = $this->extractExtends( $classLine );
		$this->methods	 = $this->extractMethods( $source );

		return true;
	}

	protected function getClassLine( string $source ): ?string
	{
		$hasClass = preg_match( '/^\s*(?<![\*\/])(final)?(abstract)?\s*class\s+[\w\s]+/mi', $source, $matches );

		if ( ! $hasClass ) {
			return null;
		}

		return $matches[0];
	}

	protected function extractName( string $classLine ): string
	{
		$hasName = preg_match( '/class\s+([\w]+)/mi', $classLine, $matches );

		if ( ! isset( $matches[1] ) ) {
			debug::out( "\t - No class ", 2 );
			return false;
		}

		debug::out( "\t - Class {$matches[1]}", 3 );
		return $matches[1];
	}

	protected function extractExtends( string $classLine ): ?string
	{
		preg_match( '/extends\s+([\w]+)/mi', $classLine, $matches );

		if ( ! isset( $matches[1] ) ) {
			return null;
		}

		debug::out( "\t - Extends {$matches[1]}", 3 );
		return $matches[1];
	}

	protected function extractMethods( string $source ): array
	{
		$hasMethods = preg_match_all( '/^\s*(?<![\*\/])(public|private|protected)?\s*(static)?\s*function\s+(\w*)\s*\((.*)\)/im', $source, $matches );

		$methods = [];
		for ( $i = 0, $len = count( $matches[0] ); $i < $len; $i ++  ) {
			$method = new Method();
			$method->setName( $matches[3][$i] )
				->setStatic( strtolower( $matches[2][$i] ) === 'static' )
				->setVisibility( empty( $matches[1][$i] ) ? 'public' : strtolower( $matches[1][$i] )  );

			$this->extractMethodParameters( $matches[4][$i], $method );

			$methods[$matches[3][$i]] = $method;
		}

		if ( debug::$level >= 4 ) { // SAVE CPU
			debug::out( "\t - Methods ", 4 );
			var_dump( $methods );
		}

		return $methods;
	}

	protected function extractMethodParameters( string $methodLine, Method $method ): Method
	{
		preg_match_all( '/(\w*)?\s*(\&)?\s*\$(\w+)(\s*\=+\s*(\'.*\'|\d+|null|true|false))?/im', $methodLine, $matches );
		$parameters = [];

		for ( $i = 0, $len = count( $matches[0] ); $i < $len; $i ++  ) {

			$parameter = new Parameter();
			$parameter	->setName( $matches[3][$i] )
						->setPassByRef( $matches[2][$i] === '&' )
						->setTypeHint( $matches[1][$i] )
						->setDefaultValue( $matches[5][$i] );

			$method->addParameter( $parameter );
		}

		return $method;
	}

	public function extendsAClass()
	{
		return $this->extends !== null;
	}

	public function getName()
	{
		return $this->name;
	}

	public function getMethods()
	{
		return $this->methods;
	}

	public function getMethod( $method )
	{
		return $this->methods[$method];
	}

	public function getExtends()
	{
		return $this->extends;
	}

	public function setParent( ClassInfo $parent )
	{
		$this->parent = $parent;
		return $this;
	}

	public function getParent(): ?ClassInfo
	{
		return $this->parent;
	}

	public function hasMethod( $method )
	{
		return isset( $this->methods[$method] );
	}
}

class Method
{

	protected $name;
	protected $static;
	protected $visibility;
	protected $parameters = [];

	public function getName(): string
	{
		return $this->name;
	}

	public function isStatic(): bool
	{
		return $this->static;
	}

	public function getVisibility(): string
	{
		return $this->visibility;
	}

	/**
	 * @return Parameter[]
	 */
	public function getParameters(): array
	{
		return $this->parameters;
	}

	public function getParameterCount()
	{
		return count( $this->parameters );
	}

	public function setName( string $name )
	{
		$this->name = trim($name);
		return $this;
	}

	public function setStatic( bool $static )
	{
		$this->static = $static;
		return $this;
	}

	public function setVisibility( string $visibility )
	{
		$this->visibility = empty( $visibility ) ? 'public' : strtolower( $visibility );
		return $this;
	}

	public function addParameter( Parameter $parameter )
	{
		$this->parameters[] = $parameter;
		return $this;
	}
}

class Parameter
{

	protected $name;
	protected $passByRef;
	protected $typeHint;
	protected $defaultValue;

	public function getName(): string
	{
		return $this->name;
	}

	public function getPassByRef(): bool
	{
		return $this->passByRef;
	}

	public function getTypeHint(): ?string
	{
		return $this->typeHint;
	}

	public function setName( string $name )
	{
		$this->name = $name;
		return $this;
	}

	public function setPassByRef( bool $passByRef )
	{
		$this->passByRef = $passByRef;
		return $this;
	}

	public function setTypeHint( ?string $typeHint )
	{

		$this->typeHint = empty( $typeHint ) ? null : $typeHint;
		return $this;
	}

	public function getDefaultValue() : string
	{
		return $this->defaultValue ?? null;
	}

	public function setDefaultValue( ?string $defaultValue )
	{
		$this->defaultValue = $defaultValue;
		return $this;
	}

	public function hasDefaultValue() : bool
	{
		return $this->defaultValue !== '';
	}

}

class FileFinder
{

	protected $startDir;
	protected $fileExtention;
	protected $list;

	public function __construct( string $startDir = '.',
		string $fileExtention = '\.php' )
	{
		$this->startDir		 = $startDir;
		$this->fileExtention = $fileExtention;
	}

	public function list(): ?array
	{
		if ( ! isset( $this->list ) ) {

			$this->populateList();
		}

		return $this->list;
	}

	protected function populateList()
	{
		$Directory	 = new RecursiveDirectoryIterator( $this->startDir );
		$Iterator	 = new RecursiveIteratorIterator( $Directory );
		$Regex		 = new RegexIterator( $Iterator, "/^.+{$this->fileExtention}$/i", RecursiveRegexIterator::GET_MATCH );

		$this->list = [];
		foreach ( $Regex as $filename ) {
			$this->list[] = $filename[0];
		}

		return $this;
	}
}

class StopWatch
{

	protected $startTime;
	protected $stopTime;
	protected $executionTime;

	public function start()
	{
		$this->startTime = microtime( true );
		return $this;
	}

	public function stop()
	{
		$this->stopTime = microtime( true );
		$this->calculateTime();
		return $this;
	}

	public function getTime()
	{
		return $this->executionTime;
	}

	public function outputTime()
	{
		echo 'Total execution time in seconds: ', $this->getTime() * 1000, 's';
	}

	protected function calculateTime()
	{
		$this->executionTime = ($this->stopTime - $this->startTime);
	}
}


// 0 - 4
debug::$level	 = 1;
$test			 = new FindIncompatibleChildClassMethods( '../modules/', '\.php' );
$test->run();
