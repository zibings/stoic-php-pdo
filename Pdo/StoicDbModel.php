<?php

	namespace Stoic\Pdo;

	/**
	 * Abstract base class that provides simplistic ORM functionality via the PdoHelper wrapper and without much
	 * fuss/overhead.
	 *
	 * @codeCoverageIgnore
	 * @package Stoic\Pdo
	 * @version 1.1.0
	 */
	abstract class StoicDbModel extends BaseDbModel {
		/**
		 * Internal PdoHelper instance;
		 *
		 * @var PdoHelper
		 */
		protected $db;


		/**
		 * Optional method to initialize an object after the constructor has finished.
		 *
		 * @throws \InvalidArgumentException
		 * @return void
		 */
		protected function __initialize() : void {
			parent::__initialize();

			if (!($this->db instanceof PdoHelper)) {
				$this->db = new PdoHelper('', null, null, null, $this->db);
			}

			return;
		}
	}
