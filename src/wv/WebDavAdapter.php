<?php
/*
 * Copyright (c) 2014, webvariants GmbH & Co. KG, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

namespace wv;

use Gaufrette\Adapter;
use Gaufrette\Filesystem;
use Sabre\DAV\Client;
use Sabre\DAV\Exception\NotFound;

class WebDavAdapter implements Adapter {
	protected $clientFactory;
	protected $directory;

	/**
	 * Constructor
	 *
	 * @param Closure $clientFactory
	 * @param string  $directory
	 */
	public function __construct($clientFactory, $directory) {
		$this->clientFactory = $clientFactory;
		$this->directory     = (string) $directory;
	}

	/**
	 * {@inheritDoc}
	 */
	public function read($key) {
		$this->ensureDirectoryExists($this->directory, false);

		$response = $this->getClient()->request('GET', $this->computePath($key));

		if ($response['statusCode'] !== 200) {
			return false;
		}

		return $response['body'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function write($key, $content) {
		$this->ensureDirectoryExists($this->directory, false);

		$path      = $this->computePath($key);
		$directory = str_replace('\\', '/', dirname($path));

		try {
			$this->ensureDirectoryExists($directory, true);
			$this->getClient()->request('PUT', $path, $content);
		}
		catch (\Exception $e) {
			return false;
		}

		return mb_strlen($content, 'ascii');
	}

	/**
	 * {@inheritDoc}
	 */
	public function rename($sourceKey, $targetKey) {
		$this->ensureDirectoryExists($this->directory, false);

		$sourcePath = $this->computePath($sourceKey);
		$targetPath = $this->computePath($targetKey);
		$targetDir  = str_replace('\\', '/', dirname($targetPath));

		$this->ensureDirectoryExists($targetDir, true);

		try {
			$this->getClient()->request('MOVE', $sourcePath, null, array(
				'Overwrite'   => 'F',
				'Destination' => $targetPath
			));

			return true;
		}
		catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function exists($key) {
		return !!$this->head($key);
	}

	/**
	 * {@inheritDoc}
	 */
	public function mtime($key) {
		$head = $this->head($key);

		return $head ? strtotime($head['headers']['last-modified']) : false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function keys() {
		$this->ensureDirectoryExists($this->directory, false);

		$data = $this->getClient()->propFind($this->directory, array(), 1);
		$keys = array();

		foreach ($data as $element) {
			$name = $element['{DAV:}displayname'];

			if (mb_strlen($name)) {
				$keys[] = $name;
			}
		}

		natcasesort($keys);

		return $keys;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete($key) {
		$this->ensureDirectoryExists($this->directory, false);

		if ($this->isDirectory($key)) {
			throw new \RuntimeException('Cannot delete entire directories at once.');
		}

		try {
			$path     = $this->computePath($key);
			$response = $this->getClient()->request('DELETE', $path);

			if ($response['statusCode'] !== 200) {
				throw new \Exception();
			}

			return true;
		}
		catch (\Exception $e) {
			return false;
		}
	}

	public function isDirectory($key) {
		$this->ensureDirectoryExists($this->directory, false);

		return $this->isDir($this->computePath($key));
	}

	protected function ensureDirectoryExists($directory, $create = false) {
		if (!$this->isDir($directory)) {
			if (!$create) {
				throw new \RuntimeException(sprintf('The directory \'%s\' does not exist.', $directory));
			}

			$this->createDirectory($directory);
		}
	}

	protected function createDirectory($directory) {
		// create parent directory if needed
		$parent = dirname($directory);

		$directory = str_replace('\\', '/', $directory);
		$parent    = str_replace('\\', '/', $parent);

		if (!$this->isDir($parent)) {
			$this->createDirectory($parent);
		}

		try {
			$this->getClient()->request('MKCOL', $directory.'/');
		}
		catch (\Exception $e) {
			throw new \RuntimeException(sprintf('Could not create the \'%s\' directory.', $directory));
		}
	}

	private function isDir($directory) {
		if ('/' === $directory) {
			return true;
		}

		$data = $this->getClient()->propFind($directory, [], 0);

		return !empty($data) && $data['{DAV:}resourcetype']->is('{DAV:}collection');
	}

	private function computePath($key) {
		return rtrim($this->directory, '/').'/'.ltrim($key, '/');
	}

	private function getClient() {
		$factory = $this->clientFactory;

		return $factory();
	}

	private function head($key) {
		$this->ensureDirectoryExists($this->directory, false);

		try {
			$file     = $this->computePath($key);
			$response = $this->getClient()->request('HEAD', $file);

			// sometimes, SabreDAV will not throw up for some bizarre reason
			if ($response['statusCode'] >= 400) {
				throw new NotFound();
			}

			return $response;
		}
		catch (NotFound $e) {
			return null;
		}
	}
}
