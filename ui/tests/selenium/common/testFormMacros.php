<?php
/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

require_once 'vendor/autoload.php';

require_once dirname(__FILE__).'/../../include/CWebTest.php';
require_once dirname(__FILE__).'/../traits/MacrosTrait.php';

/**
 * Base class for Macros tests.
 */
abstract class testFormMacros extends CWebTest {

	use MacrosTrait;

	const SQL_HOSTS = 'SELECT * FROM hosts ORDER BY hostid';

	public static function getHash() {
		return CDBHelper::getHash(self::SQL_HOSTS);
	}

	/**
	 * Test creating of host or template with Macros.
	 *
	 * @param array	$data			given data provider
	 * @param string $form_type		string used in form selector
	 * @param string $host_type		string defining is it host, template or host prototype
	 * @param boolean $is_prototype	defines is it prototype or not
	 * @param int $lld_id			points to LLD rule id where host prototype belongs
	 */
	protected function checkCreate($data, $form_type, $host_type, $is_prototype = false, $lld_id = null) {
		$this->page->login()->open(
			$is_prototype
			? 'host_prototypes.php?form=create&parent_discoveryid='.$lld_id
			: $host_type.'s.php?form=create'
		);

		$form = $this->query('name:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();
		$form->fill([ucfirst($host_type).' name' => $data['Name']]);

		if ($is_prototype) {
			$form->selectTab('Groups');
		}
		$form->fill(['Groups' => 'Zabbix servers']);

		$this->checkMacros($data, $form_type, $data['Name'], $host_type, $is_prototype, $lld_id);
	}

	/**
	 * Test updating Macros in host, host prototype or template.
	 *
	 * @param array	$data			given data provider
	 * @param string $name			name of host where changes are made
	 * @param string $form_type		string used in form selector
	 * @param string $host_type		string defining is it host, template or host prototype
	 * @param boolean $is_prototype	defines is it prototype or not
	 * @param int $lld_id			points to LLD rule id where host prototype belongs
	 */
	protected function checkUpdate($data, $name, $form_type, $host_type, $is_prototype = false, $lld_id = null) {
		$id = CDBHelper::getValue('SELECT hostid FROM hosts WHERE host='.zbx_dbstr($name));

		$this->page->login()->open(
			$is_prototype
			? 'host_prototypes.php?form=update&parent_discoveryid='.$lld_id.'&hostid='.$id
			: $host_type.'s.php?form=update&'.$host_type.'id='.$id.'&groupid=0'
		);

		$this->checkMacros($data, $form_type, $name, $host_type, $is_prototype, $lld_id);
	}

	/**
	 * Test removing Macros from host, host prototype or template.
	 *
	 * @param string $name			name of host where changes are made
	 * @param string $form_type		string used in form selector
	 * @param string $host_type		string defining is it host, template or host prototype
	 * @param boolean $is_prototype	defines is it prototype or not
	 * @param int $lld_id			points to LLD rule id where host prototype belongs
	 */
	protected function checkRemoveAll($name, $form_type, $host_type, $is_prototype = false, $lld_id = null) {
		$id = CDBHelper::getValue('SELECT hostid FROM hosts WHERE host='.zbx_dbstr($name));

		$this->page->login()->open(
			$is_prototype
				? 'host_prototypes.php?form=update&parent_discoveryid='.$lld_id.'&hostid='.$id
				: $host_type.'s.php?form=update&'.$host_type.'id='.$id.'&groupid=0'
		);

		$form = $this->query('name:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();
		$form->selectTab('Macros');
		$this->removeAllMacros();
		$form->submit();

		$message = CMessageElement::find()->one();
		$this->assertTrue($message->isGood());

		$this->assertEquals(($is_prototype ? 'Host prototype' : ucfirst($host_type)).' updated', $message->getTitle());

		$this->assertEquals(1, CDBHelper::getCount('SELECT NULL FROM hosts WHERE host='.zbx_dbstr($name)));
		// Check the results in form.
		$this->checkMacrosFields($name, $is_prototype, $lld_id, $host_type, $form_type, null);
	}

	public static function getCheckInheritedMacrosData() {
		return [
			[
				[
					'case' => 'Add new macro',
					'macros' => [
						[
							'action' => USER_ACTION_UPDATE,
							'index' => 0,
							'macro' => '{$NEW_CHECK_MACRO}',
							'value' => 'new check macro',
							'description' => 'new check macro description'
						]
					]
				]
			],
			[
				[
					'case' => 'Redefine global macro on Host',
					'macros' => [
						[
							'action' => USER_ACTION_UPDATE,
							'index' => 0,
							'macro' => '{$SNMP_COMMUNITY}',
							'value' => 'new redifined value',
							'description' => 'new redifined description'
						]
					]
				]
			],
			[
				[
					'case' => 'Redefine global macro in Inherited',
					'macros' => [
						[
							'macro' => '{$DEFAULT_DELAY}',
							'value' => '100500',
							'description' => 'new delay description'
						]
					]
				]
			]
		];
	}

	/**
	 * Test changing and resetting global macro on host, prototype or template.
	 *
	 * @param array  $data		    given data provider
	 * @param string $form_type		string used in form selector
	 * @param string $host_type		string defining is it host, template or host prototype
	 * @param boolean $is_prototype	defines is it prototype or not
	 * @param int $lld_id			points to LLD rule id where host prototype belongs
	 */
	protected function checkChangeInheritedMacros($data, $form_type, $host_type, $is_prototype = false, $lld_id = null) {
		if ($is_prototype) {
			$this->page->login()->open('host_prototypes.php?form=create&parent_discoveryid='.$lld_id);
			$form = $this->query('name:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();
			$name = 'Host prototype with edited global {#MACRO} '.time();
			$field = ($host_type !== 'template') ? 'Host name' : 'Template name';
			$form->fill([$field  => $name]);
			$form->selectTab('Groups');
			$form->fill(['Groups' => 'Zabbix servers']);
		}
		else {
			$this->page->login()->open($host_type.'s.php?form=create');
			$form = $this->query('name:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();
			$name = $host_type.' with edited global macro '.time();
			$form->fill([
				ucfirst($host_type).' name' => $name,
				'Groups' => 'Zabbix servers'
			]);
		}
		$form->selectTab('Macros');
		$radio_switcher = $this->query('id:show_inherited_macros')->asSegmentedRadio()->waitUntilPresent()->one();

		switch ($data['case']) {
			case 'Add new macro':
				$radio_switcher->fill('Inherited and '.$host_type.' macros');
				$this->page->waitUntilReady();

				// Get all global macros before changes.
				$global_macros = $this->getGlobalMacrosFrotendTable();

				// Return to object's macros.
				$radio_switcher->fill(ucfirst($host_type).' macros');
				$this->page->waitUntilReady();
				$this->fillMacros($data['macros']);

				// Get all object's macros.
				$hostmacros = $this->getMacros();

				// By default macro type is Text, which reffers to 0.
				foreach ($hostmacros as &$macro) {
					$macro['type'] = 0;
				}
				unset($macro);

				// Go to global macros.
				$radio_switcher->fill('Inherited and '.$host_type.' macros');
				$this->page->waitUntilReady();

				// Check that host macro is editable.
				foreach ($data['macros'] as $data_macro) {
					$this->assertTrue($this->query('xpath://textarea[text()='.CXPathHelper::escapeQuotes($data_macro['macro']).
							']')->waitUntilPresent()->one()->isEnabled()
					);

					$this->assertTrue($this->getValueField($data_macro['macro'])->isEnabled());

					// Get macro index.
					$macro_index = explode('_', $this->query('xpath://textarea[text()='.
							CXPathHelper::escapeQuotes($data_macro['macro']).']')->one()->getAttribute('id'), 3
					);

					// Fill macro description by new description using found macro index.
					$this->assertTrue($this->query('id:macros_'.$macro_index[1].'_description')->one()->isEnabled());
				}

				// Add newly added macros to global macros array.
				$expected_global_macros = array_merge($global_macros, $hostmacros);

				// Compare new macros table from global and inherited macros page with expected result.
				$this->assertEquals($this->sortMacros($expected_global_macros), $this->getGlobalMacrosFrotendTable());
				break;

			case 'Redefine global macro on Host':
				$radio_switcher->fill('Inherited and '.$host_type.' macros');
				$this->page->waitUntilReady();

				// Get all global macros before changes.
				$global_macros = $this->getGlobalMacrosFrotendTable();

				// Return to object's macros.
				$radio_switcher->fill(ucfirst($host_type).' macros');
				$this->page->waitUntilReady();
				$this->fillMacros($data['macros']);

				// Redefine macro values in expected Global macros.
				foreach ($data['macros'] as $data_macro) {
					foreach ($global_macros as &$global_macro) {
						if ($global_macro['macro'] === $data_macro['macro']) {
							$global_macro['value'] = $data_macro['value'];
							$global_macro['description'] = $data_macro['description'];
						}
					}
					unset($global_macro);
				}
				$expected_global_macros = $global_macros;

				// Compare new macros table from global and inherited macros page with expected result.
				$radio_switcher->fill('Inherited and '.$host_type.' macros');
				$this->page->waitUntilReady();

				// Check enabled/disabled fields.
				foreach ($data['macros'] as $data_macro) {
					$this->assertFalse($this->query('xpath://textarea[text()='.CXPathHelper::escapeQuotes($data_macro['macro']).']')
							->waitUntilPresent()->one()->isEnabled()
					);

					$this->assertTrue($this->getValueField($data_macro['macro'])->isEnabled());
					// Get macro index.
					$macro_index = explode('_', $this->query('xpath://textarea[text()='.
							CXPathHelper::escapeQuotes($data_macro['macro']).']')->one()->getAttribute('id'), 3
					);

					// Fill macro description by new description using found macro index.
					$this->assertTrue($this->query('id:macros_'.$macro_index[1].'_description')->one()->isEnabled());
					$this->assertTrue($this->query('xpath://textarea[text()='.
							CXPathHelper::escapeQuotes($data_macro['macro']).']/../..//button[text()="Remove"]')->exists()
					);
				}

				$this->assertEquals($expected_global_macros, $this->getGlobalMacrosFrotendTable());
				break;

			case 'Redefine global macro in Inherited':
				// Get all object's macros.
				$hostmacros = $this->getMacros();

				$radio_switcher->fill('Inherited and '.$host_type.' macros');
				$this->page->waitUntilReady();

				foreach ($data['macros'] as $data_macro) {
					// Find necessary row by macro name and click Change button.
					$this->query('xpath://textarea[text()='.CXPathHelper::escapeQuotes($data_macro['macro']).
							']/../..//button[text()="Change"]')->waitUntilPresent()->one()->click();

					// Fill macro value by new value.
					$this->getValueField($data_macro['macro'])->fill($data_macro['value']);

					// Get macro index.
					$macro_index = explode('_', $this->query('xpath://textarea[text()='.
							CXPathHelper::escapeQuotes($data_macro['macro']).']')->one()->getAttribute('id'), 3);

					// Fill macro description by new description using found macro index.
					$this->query('id:macros_'.$macro_index[1].'_description')->one()->fill($data_macro['description']);
				}

				$radio_switcher->fill(ucfirst($host_type).' macros');
				$this->page->waitUntilReady();
				$expected_hostmacros = ($hostmacros[0]['macro'] !== '')
					? array_merge($data['macros'], $hostmacros)
					: $data['macros'];

				// Compare host macros table with expected result.
				$this->assertEquals($this->sortMacros($expected_hostmacros), $this->getMacros());
				break;
		}

		$form->submit();

		// Check saved edited macros in host/template form.
		$id = CDBHelper::getValue('SELECT hostid FROM hosts WHERE host='.zbx_dbstr($name));

		$this->page->open(
			$is_prototype
			? 'host_prototypes.php?form=update&parent_discoveryid='.$lld_id.'&hostid='.$id
			: $host_type.'s.php?form=update&'.$host_type.'id='.$id.'&groupid=0'
		);

		$form->selectTab('Macros');
/*
		$form->selectTab('Macros');
		$this->assertMacros();

		// Check inherited macros again after remove.
		$this->query('xpath://label[@for="show_inherited_macros_1"]')->waitUntilPresent()->one()->click();
		$this->checkInheritedGlobalMacros();
 */
	}


	public static function getRemoveInheritedMacrosData() {
		return [
			[
				[
					'case' => 'Remove macro from Host',
					'macros' => [
						[
							'macro' => '{$MACRO_FOR_DELETE_HOST1}'
						],
						[
							'macro' => '{$MACRO_FOR_DELETE_HOST2}'
						]
					]
				]
			],
			[
				[
					'case' => 'Remove macro from Inherited',
					'macros' => [
						[
							'macro' => '{$MACRO_FOR_DELETE_GLOBAL1}'
						],
						[
							'macro' => '{$MACRO_FOR_DELETE_GLOBAL2}'
						]
					]
				]
			],
			[
				[
					'case' => 'Remove redefined macro in Inherited',
					'macros' => [
						[
							'macro' => '{$SNMP_COMMUNITY}',
							'value' => 'public',
							'description' => ''
						]
					]
				]
			]
		];
	}

	/**
	 * Test removing and resetting global macro on host, prototype or template.
	 *
	 * @param array  $data		    given data provider
	 * @param array  $id		    host's, prototype's or template's id
	 * @param string $form_type		string used in form selector
	 * @param string $host_type		string defining is it host, template or host prototype
	 * @param boolean $is_prototype	defines is it prototype or not
	 * @param int $lld_id			points to LLD rule id where host prototype belongs
	 */
	protected function checkRemoveInheritedMacros($data, $id, $form_type, $host_type, $is_prototype = false, $lld_id = null) {
		$this->page->login()->open(
			$is_prototype
				? 'host_prototypes.php?form=update&parent_discoveryid='.$lld_id.'&hostid='.$id
				: $host_type.'s.php?form=update&'.$host_type.'id='.$id.'&groupid=0'
		);

		$form = $this->query('name:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();
		$form->selectTab('Macros');
		$radio_switcher = $this->query('id:show_inherited_macros')->asSegmentedRadio()->waitUntilPresent()->one();

		switch ($data['case']) {
			case 'Remove macro from Host':
				$radio_switcher->fill('Inherited and '.$host_type.' macros');
				$this->page->waitUntilReady();

				// Get all global macros before changes.
				$global_macros = $this->getGlobalMacrosFrotendTable();

				// Return to object's macros.
				$radio_switcher->fill(ucfirst($host_type).' macros');
				$this->page->waitUntilReady();
				$this->removeMacros($data['macros']);

				$radio_switcher->fill('Inherited and '.$host_type.' macros');
				$this->page->waitUntilReady();

				foreach ($data['macros'] as $data_macro) {
					foreach ($global_macros as $i => &$global_macro) {
						if ($global_macro['macro'] === $data_macro['macro']) {
							unset($global_macros[$i]);
						}
					}
					unset($global_macro);
				}
				$expected_global_macros = $global_macros;

				$this->assertEquals($this->sortMacros($expected_global_macros), $this->getGlobalMacrosFrotendTable());
				break;

			case 'Remove macro from Inherited':
				// Get all object's macros.
				$hostmacros = $this->getMacros();

				$radio_switcher->fill('Inherited and '.$host_type.' macros');
				$this->page->waitUntilReady();

				$this->removeMacros($data['macros']);

				$radio_switcher->fill(ucfirst($host_type).' macros');
				$this->page->waitUntilReady();

				foreach ($data['macros'] as $data_macro) {
					foreach ($hostmacros as $i => &$hostmacro) {
						if ($hostmacro['macro'] === $data_macro['macro']) {
							unset($hostmacros[$i]);
						}
					}
					unset($hostmacro);
				}
				$expected_hostmacros = $hostmacros;

				// Compare host macros table with expected result.
				$this->assertEquals($this->sortMacros($expected_hostmacros), $this->getMacros());
				break;

			case 'Remove redefined macro in Inherited':
				// Get all object's macros before changes.
				$hostmacros = $this->getMacros();

				$radio_switcher->fill('Inherited and '.$host_type.' macros');
				$this->page->waitUntilReady();

				$this->removeMacros($data['macros']);

				$radio_switcher->fill(ucfirst($host_type).' macros');
				$this->page->waitUntilReady();

				// Delete reset macros from hostmacros array.
				foreach ($data['macros'] as $data_macro) {
					foreach ($hostmacros as $i => &$hostmacro) {
						if ($hostmacro['macro'] === $data_macro['macro']) {
							unset($hostmacros[$i]);
						}
					}
					unset($hostmacro);
				}

				$expected_hostmacros = ($hostmacros === [])
					? [[ 'macro' => '', 'value' => '', 'description' => '']]
					: $hostmacros;

				// Check that reset macros were deleted from hostmacros array.
				$this->assertEquals($this->sortMacros($expected_hostmacros), $this->getMacros());

				// Return to Global macros table and check fields and values there.
				$radio_switcher->fill('Inherited and '.$host_type.' macros');
				$this->page->waitUntilReady();

				// Check enabled/disabled fields and values.
				foreach ($data['macros'] as $data_macro) {
					$this->assertTrue($this->query('xpath://textarea[text()='.CXPathHelper::escapeQuotes($data_macro['macro']).
							']/../..//button[text()="Change"]')->exists()
					);

					// Check macro field disabled.
					$this->assertFalse($this->query('xpath://textarea[text()='.CXPathHelper::escapeQuotes($data_macro['macro']).']')
							->waitUntilPresent()->one()->isEnabled()
					);

					// Check macro value and disabled field.
					$this->assertFalse($this->query('xpath://textarea[text()='.CXPathHelper::escapeQuotes($data_macro['macro']).
							']/../..//div[contains(@class, "macro-value")]/textarea')->waitUntilPresent()->one()->isEnabled()
					);
					$this->assertEquals($data_macro['value'], $this->getValueField($data_macro['macro'])->getValue());

					// Get macro index.
					$macro_index = explode('_', $this->query('xpath://textarea[text()='.
							CXPathHelper::escapeQuotes($data_macro['macro']).']')->one()->getAttribute('id'), 3);

					// Check macro description and disabled field.
					$this->assertFalse($this->query('id:macros_'.$macro_index[1].'_description')->one()->isEnabled());
					$this->assertEquals($data_macro['description'],
							$this->query('id:macros_'.$macro_index[1].'_description')->one()->getValue()
					);
				}
				break;
		}

		$form->submit();

		// Check form and DB.

	}

	/**
	 *  Check adding and saving macros in host, host prototype or template form.
	 *
	 * @param array	$data			given data provider
	 * @param string $form_type		string used in form selector
	 * @param string $name			name of host where changes are made
	 * @param string $host_type		string defining is it host, template or host prototype
	 * @param boolean $is_prototype	defines is it prototype or not
	 * @param int $lld_id			points to LLD rule id where host prototype belongs
	 */
	private function checkMacros($data = null, $form_type, $name, $host_type, $is_prototype, $lld_id) {
		if ($data['expected'] === TEST_BAD) {
			$old_hash = $this->getHash();
		}

		$form = $this->query('name:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();
		$form->selectTab('Macros');
		$this->fillMacros($data['macros']);
		$form->submit();

		$message = CMessageElement::find()->one();
		switch ($data['expected']) {
			case TEST_GOOD:
				$this->assertTrue($message->isGood());
				$this->assertEquals($data['success_message'], $message->getTitle());
				$this->assertEquals(1, CDBHelper::getCount('SELECT NULL FROM hosts WHERE host='.zbx_dbstr($name)));
				// Check the results in form.
				$this->checkMacrosFields($name, $is_prototype, $lld_id, $host_type, $form_type, $data);
				break;
			case TEST_BAD:
				$this->assertTrue($message->isBad());
				$this->assertEquals($data['error_message'], $message->getTitle());
				$this->assertTrue($message->hasLine($data['error_details']));
				// Check that DB hash is not changed.
				$this->assertEquals($old_hash, CDBHelper::getHash(self::SQL_HOSTS));
				break;
		}
	}

	/**
	 * Checking saved macros in host, host prototype or template form.
	 *
	 * @param string $name			name of host where changes are made
	 * @param boolean $is_prototype	defines is it prototype or not
	 * @param int $lld_id			points to LLD rule id where host prototype belongs
	 * @param string $host_type		string defining is it host, template or host prototype
	 * @param string $form_type		string used in form selector
	 * @param array	$data			given data provider
	 */
	private function checkMacrosFields($name, $is_prototype, $lld_id, $host_type, $form_type,  $data = null) {
		$id = CDBHelper::getValue('SELECT hostid FROM hosts WHERE host='.zbx_dbstr($name));

		$this->page->open(
			$is_prototype
			? 'host_prototypes.php?form=update&parent_discoveryid='.$lld_id.'&hostid='.$id
			: $host_type.'s.php?form=update&'.$host_type.'id='.$id.'&groupid=0'
		);

		$form = $this->query('id:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();
		$form->selectTab('Macros');
		$this->assertMacros(($data !== null) ? $data['macros'] : []);
		$this->query('xpath://label[@for="show_inherited_macros_1"]')->waitUntilPresent()->one()->click();
		// Get all macros defined for this host.
		$hostmacros = CDBHelper::getAll('SELECT macro, value, description, type FROM hostmacro where hostid ='.$id);

		$this->checkInheritedGlobalMacros($hostmacros);
	}

	/**
	 * Check host/host prototype/template inherited macros in form matching with global macros in DB.
	 *
	 * @param array $hostmacros		all macros defined particularly for this host
	 */
	public function checkInheritedGlobalMacros($hostmacros = []) {
		// Create two macros arrays: from DB and from Frontend form.
		$macros_db = array_merge(
			CDBHelper::getAll('SELECT macro, value, description, type FROM globalmacro'),
			$hostmacros
		);

		// If the macro is expected to have type "Secret text", replace the value from db with the secret macro pattern.
		for ($i = 0; $i < count($macros_db); $i++) {
			if (intval($macros_db[$i]['type']) === ZBX_MACRO_TYPE_SECRET) {
				$macros_db[$i]['value'] = '******';
			}
		}

		// Compare macros from DB with macros from Frontend.
		$this->assertEquals($this->sortMacros($macros_db), $this->getGlobalMacrosFrotendTable());
	}

	/**
	 *
	 */
	public function getGlobalMacrosFrotendTable() {
		// Write macros rows from Frontend to array.
		$macros_frontend = [];
		$table = $this->query('id:tbl_macros')->waitUntilVisible()->asTable()->one();
		$count = $table->getRows()->count() - 1;
		for ($i = 0; $i < $count; $i += 2) {
			$macro = [];
			$row = $table->getRow($i);
			$macro['macro'] = $row->query('xpath:./td[1]/textarea')->one()->getValue();
			$macro_value = $this->getValueField($macro['macro']);
			$macro['value'] = $macro_value->getValue();
			$macro['description'] = $table->getRow($i + 1)->query('tag:textarea')->one()->getValue();
			$macro['type'] = ($macro_value->getInputType() === CInputGroupElement::TYPE_SECRET) ?
					ZBX_MACRO_TYPE_SECRET : ZBX_MACRO_TYPE_TEXT;
			$macros_frontend[] = $macro;
		}

		return $this->sortMacros($macros_frontend);
	}

	/**
	 * Check content of macro value InputGroup element for macros.
	 *
	 * @param array		$data		given data provider
	 * @param string	$url		url of configuration form of the corresponding entity
	 * @param string	$source		type of entity that is being checked (hots, hostPrototype, template)
	 */
	public function checkSecretMacrosLayout($data, $url, $source) {
		$this->openMacrosTab($url, $source, true);

		// Check that value field is disabled for global macros in "Inherited and host macros" tab.
		if (CTestArrayHelper::get($data, 'global', false)) {
			$this->query('xpath://label[@for="show_inherited_macros_1"]')->waitUntilPresent()->one()->click();
			$value_field = $this->getValueField($data['macro']);
			$change_button = $value_field->getNewValueButton();
			$revert_button = $value_field->getRevertButton();

			if ($data['type'] === CInputGroupElement::TYPE_TEXT) {
				$this->assertTrue($value_field->query('xpath:./textarea')->one()->isAttributePresent('readonly'));
				$this->assertEquals(255, $value_field->query('xpath:./textarea')->one()->getAttribute('maxlength'));
				$this->assertFalse($change_button->isValid());
				$this->assertFalse($revert_button->isValid());
			}
			else {
				$this->assertFalse($value_field->query('xpath:.//input')->one()->isEnabled());
				$this->assertEquals(255, $value_field->query('xpath:.//input')->one()->getAttribute('maxlength'));
				$this->assertFalse($change_button->isEnabled());
				$this->assertFalse($revert_button->isClickable());
			}
			$this->assertFalse($value_field->query('xpath:.//button[contains(@class, "btn-dropdown-toggle")]')->one()->isEnabled());
		}
		else {
			$value_field = $this->getValueField($data['macro']);
			$change_button = $value_field->getNewValueButton();
			$revert_button = $value_field->getRevertButton();
			$textarea_xpath = 'xpath:.//textarea[contains(@class, "textarea-flexible")]';

			if ($data['type'] === CInputGroupElement::TYPE_SECRET) {
				$this->assertFalse($value_field->query($textarea_xpath)->exists());
				$this->assertEquals(255, $value_field->query('xpath:.//input')->one()->getAttribute('maxlength'));

				$this->assertTrue($change_button->isValid());
				$this->assertFalse($revert_button->isClickable());
				// Change value text or type and check that New value button is not displayed and Revert button appeared.
				if (CTestArrayHelper::get($data, 'change_type', false)) {
					$value_field->changeInputType(CInputGroupElement::TYPE_TEXT);
				}
				else {
					$change_button->click();
				}
				$value_field->invalidate();

				$this->assertFalse($change_button->isEnabled());
				$this->assertTrue($revert_button->isClickable());
			}
			else {
				$this->assertTrue($value_field->query($textarea_xpath)->exists());
				$this->assertEquals(255, $value_field->query('xpath:./textarea')->one()->getAttribute('maxlength'));
				$this->assertFalse($change_button->isValid());
				$this->assertFalse($revert_button->isValid());

				// Change value type to "Secret text" and check that new value and revert buttons were not added.
				$value_field->changeInputType(CInputGroupElement::TYPE_SECRET);
				$value_field->invalidate();

				$this->assertFalse($value_field->getNewValueButton()->isValid());
				$this->assertFalse($value_field->getRevertButton()->isValid());
			}
		}
	}

	/**
	 * Check adding and saving secret macros for host, host prototype and template entities.
	 *
	 * @param array		$data		given data provider
	 * @param string	$url		url of configuration form of the corresponding entity
	 * @param string	$source		type of entity that is being checked (hots, hostPrototype, template)
	 */
	public function createSecretMacros($data, $url, $source) {
		$this->openMacrosTab($url, $source, true);

		// Check that macro values have type plain text by default.
		if (CTestArrayHelper::get($data, 'check_default_type', false)){
			$this->assertEquals(CInputGroupElement::TYPE_TEXT, $this->query('xpath://div[contains(@class, "macro-value")]')
					->one()->asInputGroup()->getInputType());
		}

		$this->fillMacros([$data['macro_fields']]);
		$value_field = $this->query('xpath://div[contains(@class, "macro-value")]')->all()->last()->asInputGroup();

		// Check that macro type is set correctly.
		$this->assertEquals($data['macro_fields']['value']['type'], $value_field->getInputType());

		// Check that textarea input element is not available for secret text macros.
		$this->assertEquals($data['macro_fields']['value']['text'], $value_field->getValue());

		// Switch to tab with inherited and instance macros and verify that the value is secret but is still accessible.
		$this->checkInheritedTab($data['macro_fields'], true);
		// Check that macro value is hidden but is still accessible after switching back to instance macros list.
		$value_field = $this->getValueField($data['macro_fields']['macro']);
		$this->assertEquals(CInputGroupElement::TYPE_SECRET, $value_field->getInputType());

		// Change macro type back to text (is needed) before saving the changes.
		if (CTestArrayHelper::get($data, 'back_to_text', false)) {
			$value_field->changeInputType(CInputGroupElement::TYPE_TEXT);
		}

		$this->query('button:Update')->one()->click();
		$this->openMacrosTab($url, $source);
		$value_field = $this->getValueField($data['macro_fields']['macro']);

		if (CTestArrayHelper::get($data, 'back_to_text', false)) {
			$this->assertEquals($data['macro_fields']['value']['text'], $value_field->getValue());
			// Switch to tab with inherited and instance macros and verify that the value is plain text.
			$this->checkInheritedTab($data['macro_fields'], false);
		}
		else {
			$this->assertEquals('******', $value_field->getValue());
			// Switch to tab with inherited and instance macros and verify that the value is secret and is not accessible.
			$this->checkInheritedTab($data['macro_fields'], true, false);
		}

		// Check macro value, type and description in DB.
		$sql = 'SELECT value, description, type FROM hostmacro WHERE macro='.zbx_dbstr($data['macro_fields']['macro']);
		$type = (CTestArrayHelper::get($data, 'back_to_text', false)) ? ZBX_MACRO_TYPE_TEXT : ZBX_MACRO_TYPE_SECRET;
		$this->assertEquals([$data['macro_fields']['value']['text'], $data['macro_fields']['description'], $type],
				array_values(CDBHelper::getRow($sql)));
	}

	/**
	 *  Check updateof secret macros for host, host prototype and template entities.
	 *
	 * @param array		$data		given data provider
	 * @param string	$url		url of configuration form of the corresponding entity
	 * @param string	$source		type of entity that is being checked (hots, hostPrototype, template)
	 */
	public function updateSecretMacros($data, $url, $source) {
		$this->openMacrosTab($url, $source, true);
		$this->fillMacros([$data]);

		// Check that new values are correct in Inherited and host prototype macros tab before saving the values.
		$value_field = $this->getValueField($data['macro']);
		$secret = (CTestArrayHelper::get($data['value'], 'type', CInputGroupElement::TYPE_SECRET) ===
				CInputGroupElement::TYPE_SECRET) ? true : false;
		$this->checkInheritedTab($data, $secret);

		$this->query('button:Update')->one()->click();
		$this->openMacrosTab($url, $source);

		$value_field = $this->getValueField($data['macro']);
		if (CTestArrayHelper::get($data['value'], 'type', CInputGroupElement::TYPE_SECRET) === CInputGroupElement::TYPE_SECRET) {
			$this->assertEquals(CInputGroupElement::TYPE_SECRET, $value_field->getInputType());
			$this->assertEquals('******', $value_field->getValue());
			$this->checkInheritedTab($data, true, false);
		}
		else {
			$this->assertEquals(CInputGroupElement::TYPE_TEXT, $value_field->getInputType());
			$this->assertEquals($data['value']['text'], $value_field->getValue());
			$this->checkInheritedTab($data, false);
		}
		// Check in DB that values of the updated macros are correct.
		$sql = 'SELECT value FROM hostmacro WHERE macro='.zbx_dbstr($data['macro']);
		$this->assertEquals($data['value']['text'], CDBHelper::getValue($sql));
	}

	/**
	 *  Check that it is possible to revert secret macro changes for host, host prototype and template entities.
	 *
	 * @param array		$data		given data provider
	 * @param string	$url		url of configuration form of the corresponding entity
	 * @param string	$source		type of entity that is being checked (hots, hostPrototype, template)
	 */
	public function revertSecretMacroChanges($data, $url, $source) {
		$this->openMacrosTab($url, $source, true);

		$sql = 'SELECT * FROM hostmacro WHERE macro='.CDBHelper::escape($data['macro_fields']['macro']);
		$old_values = CDBHelper::getRow($sql);

		$value_field = $this->getValueField($data['macro_fields']['macro']);

		// Check that the existing macro value is hidden.
		$this->assertEquals('******', $value_field->getValue());

		// Change the value of the secret macro
		$value_field->getNewValueButton()->click();
		$this->assertEquals('', $value_field->getValue());
		$value_field->fill('New_macro_value');

		if (CTestArrayHelper::get($data, 'set_to_text', false)) {
			$value_field->changeInputType(CInputGroupElement::TYPE_TEXT);
			$this->assertEquals('New_macro_value', $value_field->getValue());
		}

		// Press revert button and save the changes.
		$value_field->getRevertButton()->click();
		$this->query('button:Update')->one()->click();

		// Check that no macro value changes took place.
		$this->openMacrosTab($url, $source);
		$this->assertEquals('******', $this->getValueField($data['macro_fields']['macro'])->getValue());
		$this->assertEquals($old_values, CDBHelper::getRow($sql));
	}

	/**
	 *  Check how secret macro is resolved in item name for host, host prototype and template entities.
	 *
	 * @param array		$macro		given macro
	 * @param string	$url		url of configuration form of the corresponding entity
	 * @param string	$source		type of entity that is being checked (hots, hostPrototype, template)
	 */
	public function resolveSecretMacro($macro, $url, $source) {
		$this->page->login()->open($url)->waitUntilReady();
		$this->query('link:Items')->one()->click();
		$this->page->waitUntilReady();

		$this->assertTrue($this->query('link', 'Macro value: '.$macro['value'])->exists());

		$this->openMacrosTab($url, $source);

		$value_field = $this->getValueField($macro['macro']);
		$value_field->changeInputType(CInputGroupElement::TYPE_SECRET);

		$this->query('button:Update')->one()->click();
		$this->openMacrosTab($url, $source);

		$this->query('link:Items')->one()->click();
		$this->page->waitUntilReady();

		$this->assertTrue($this->query('link', 'Macro value: ******')->exists());
	}

	/**
	 * Function opens Inherited and instance macros tab and checks the value, it the value has type Secret text and if
	 * the value is displayed.
	 *
	 * @param type $data		given data provider
	 * @param type $secret		flag that indicates if the value should have type "Secret text".
	 * @param type $available	flag that indicates if the value should be available.
	 */
	public function checkInheritedTab($data, $secret, $available = true) {
		// Switch to the list of inherited and instance macros.
		$this->query('xpath://label[@for="show_inherited_macros_1"]')->waitUntilPresent()->one()->click();
		$this->query('class:is-loading')->waitUntilNotPresent();
		$value_field = $this->getValueField($data['macro']);

		if ($secret) {
			$this->assertEquals(CInputGroupElement::TYPE_SECRET, $value_field->getInputType());
			$expected_value = ($available) ? $data['value']['text'] : '******';
			$this->assertEquals($expected_value, $value_field->getValue());
		}
		else {
			$this->assertEquals(CInputGroupElement::TYPE_TEXT, $value_field->getInputType());
			$this->assertEquals($data['value']['text'], $value_field->getValue());
		}
		// Switch back to the list of instance macros.
		$this->query('xpath://label[@for="show_inherited_macros_0"]')->waitUntilPresent()->one()->click();
		$this->query('class:is-loading')->waitUntilNotPresent();
	}

	/**
	 * Function opens Macros tab in corresponding instance configuration form.
	 *
	 * @param type $url			URL that leads to the configuration form of corresponding entity
	 * @param type $source		type of entity that is being checked (hots, hostPrototype, template)
	 * @param type $login		flag that indicates whether login should occur before opening the configuration form
	 */
	private function openMacrosTab($url, $source, $login = false) {
		if ($login) {
			$this->page->login();
		}
		$this->page->open($url)->waitUntilReady();
		$this->query('id:'.$source.'Form')->asForm()->one()->selectTab('Macros');
	}

	/**
	 * Sort all macros array by Macros.
	 *
	 * @param array $macros    macros to be sorted
	 *
	 * @return array
	 */
	private function sortMacros($macros) {
		usort($macros, function ($a, $b) {
			return strcmp($a['macro'], $b['macro']);
		});

		return $macros;
	}
}
