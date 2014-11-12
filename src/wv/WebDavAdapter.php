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

	public function read($key) {
		$this->ensureDirectoryExists($this->directory, false);

		$response = $this->getClient()->request('GET', $this->computePath($key));

		return $response['body'];
	}

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

	public function rename($sourceKey, $targetKey) {
		$this->ensureDirectoryExists($this->directory, false);

		$sourcePath = $this->computePath($sourceKey);
		$targetPath = $this->computePath($targetKey);

		$this->ensureDirectoryExists(dirname($targetPath), true);

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

	public function exists($key) {
		$this->ensureDirectoryExists($this->directory, false);

		try {
			$file     = $this->computePath($key);
			$response = $this->getClient()->request('HEAD', $file);

			// sometimes, SabreDAV will not throw up for some bizarre reason
			if ($response['statusCode'] >= 400) {
				throw new NotFound();
			}

			return true;
		}
		catch (NotFound $e) {
			return false;
		}
	}

	public function keys()
	{
		$this->ensureDirectoryExists($this->directory, false);

		$keys = $this->fetchKeys();

		return $keys['keys'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function mtime($key)
	{
		$this->ensureDirectoryExists($this->directory, false);

		$mtime = ftp_mdtm($this->getConnection(), $this->computePath($key));

		// the server does not support this function
		if (-1 === $mtime) {
			throw new \RuntimeException('Server does not support ftp_mdtm function.');
		}

		return $mtime;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete($key)
	{
		$this->ensureDirectoryExists($this->directory, false);

		if ($this->isDirectory($key)) {
			return ftp_rmdir($this->getConnection(), $this->computePath($key));
		}

		return ftp_delete($this->getConnection(), $this->computePath($key));
	}

	public function isDirectory($key) {
		$this->ensureDirectoryExists($this->directory, false);

		return $this->isDir($this->computePath($key));
	}

	/**
	 * Lists files from the specified directory. If a pattern is
	 * specified, it only returns files matching it.
	 *
	 * @param string $directory The path of the directory to list from
	 *
	 * @return array An array of keys and dirs
	 */
	public function listDirectory($directory = '')
	{
		$this->ensureDirectoryExists($this->directory, false);

		$directory = preg_replace('/^[\/]*([^\/].*)$/', '/$1', $directory);

		$items = $this->parseRawlist(
			ftp_rawlist($this->getConnection(), '-al ' . $this->directory . $directory ) ? : array()
		);

		$fileData = $dirs = array();
		foreach ($items as $itemData) {

			if ('..' === $itemData['name'] || '.' === $itemData['name']) {
				continue;
			}

			$item = array(
				'name'  => $itemData['name'],
				'path'  => trim(($directory ? $directory . '/' : '') . $itemData['name'], '/'),
				'time'  => $itemData['time'],
				'size'  => $itemData['size'],
			);

			if ('-' === substr($itemData['perms'], 0, 1)) {
				$fileData[$item['path']] = $item;
			} elseif ('d' === substr($itemData['perms'], 0, 1)) {
				$dirs[] = $item['path'];
			}
		}

		$this->fileData = array_merge($fileData, $this->fileData);

		return array(
		   'keys'   => array_keys($fileData),
		   'dirs'   => $dirs
		);
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
		if (!$this->isDir($parent)) {
			$this->createDirectory($parent);
		}

		// create the specified directory
		$created = ftp_mkdir($this->getConnection(), $directory);
		if (false === $created) {
			throw new \RuntimeException(sprintf('Could not create the \'%s\' directory.', $directory));
		}
	}

	/**
	 * @param  string  $directory - full directory path
	 * @return boolean
	 */
	private function isDir($directory)
	{
		if ('/' === $directory) {
			return true;
		}

		if (!@ftp_chdir($this->getConnection(), $directory)) {
			return false;
		}

		// change directory again to return in the base directory
		ftp_chdir($this->getConnection(), $this->directory);

		return true;
	}

	private function fetchKeys($directory = '', $onlyKeys = true)
	{
		$directory = preg_replace('/^[\/]*([^\/].*)$/', '/$1', $directory);

		$lines = ftp_rawlist($this->getConnection(), '-alR '. $this->directory . $directory);

		if (false === $lines) {
			return array();
		}

		$regexDir = '/'.preg_quote($this->directory . $directory, '/').'\/?(.+):$/u';
		$regexItem = '/^(?:([d\-\d])\S+)\s+\S+(?:(?:\s+\S+){5})?\s+(\S+)\s+(.+?)$/';

		$prevLine = null;
		$directories = array();
		$keys = array('keys' => array(), 'dirs' => array());

		foreach ((array) $lines as $line) {
			if ('' === $prevLine && preg_match($regexDir, $line, $match)) {
				$directory = $match[1];
				unset($directories[$directory]);
				if ($onlyKeys) {
					$keys = array(
						'keys' => array_merge($keys['keys'], $keys['dirs']),
						'dirs' => array()
					);
				}
			} elseif (preg_match($regexItem, $line, $tokens)) {
				$name = $tokens[3];

				if ('.' === $name || '..' === $name) {
					continue;
				}

				$path = ltrim($directory . '/' . $name, '/');

				if ('d' === $tokens[1] || '<dir>' === $tokens[2]) {
					$keys['dirs'][] = $path;
					$directories[$path] = true;
				} else {
					$keys['keys'][] = $path;
				}
			}
			$prevLine = $line;
		}

		if ($onlyKeys) {
			$keys = array(
				'keys' => array_merge($keys['keys'], $keys['dirs']),
				'dirs' => array()
			);
		}

		foreach (array_keys($directories) as $directory) {
			$keys = array_merge_recursive($keys, $this->fetchKeys($directory, $onlyKeys));
		}

		return $keys;
	}

	private function computePath($key) {
		return rtrim($this->directory, '/') . '/' . $key;
	}

	private function getClient() {
		$factory = $this->clientFactory;

		return $factory();
	}
}
