<?php

/**
 * @author Daniel Robenek
 * @license MIT
 */

namespace DependentSelectBox;

use Haxtip\Forms\SelectBox;
use InvalidArgumentException;
use LogicException;
use Nette\NotSupportedException;
use Nette\Forms\Container as FormContainer;
use Exception;


class DependentSelectBox extends SelectBox {

// <editor-fold defaultstate="collapsed" desc="variables">

	/** @var boolean "Disable" child DependentSelectBox-es or select 1st value? (Disabling does not effect validation) */
	public static $disableChilds = false;
	/** @var string Html class set on "disabled" control */
	public static $disabledHtmlClass = "disabledControl";
	/** @var Title for unselected value */
	public static $emptyValueTitle = "- - - - -";
	/** @var Title for disabled value */
	public static $disabledItemTitle = "x x x x x";
	/** @var Select first item for root SelectBox ? */
	public $autoSelectRootFirstItem = true;

	/** @var array(SelectBox|DependentSelectBox) Form control/s on which is this DependentSelectBox attached */
	protected $parents;
	/** @var callback Callback to function which returns items for selectbox */
	protected $dataCallback;
	/** @var array of DependentSelectBox Childs which are attached on this DependentSelectBox */
	protected $childs = array();
	/** @var array Registred onSubmit callbacks */
	protected $onSubmit = array(); // array(array(callback, array(params)), array(callback, array(params)), ....)
	/** @boolean Is this component in "disabled" state? */
	protected $isDisabled;
	/** @array array(key => value) for "disabled" select box */
	protected $disabledValue = null;
	/** @bool Leave first empty line in all states? */
	protected $leaveFirstEmpty = false;

	/** Unselected values */
	private static $emptyValues = array('', null);


// </editor-fold>

// <editor-fold defaultstate="collapsed" desc="constructor, initializing, attaching">

	/**
	 * Constructor
	 * @param string $label Label of control
	 * @param SelectBox|DependentSelectBox|FormControlDependencyHelper $depends SelectBox on which is this control attached
	 * @param callback $dataCallback Callback for data retreiving function($form, $dependentSelectBox)
	 */
	public function __construct($label, $parents, $dataCallback) {
		parent::__construct($label, null, null);
		$this->dataCallback = new \Nette\Callback($dataCallback);
		if(!is_array($parents))
			$parents = ($parents === null) ? array() : array($parents);
		$this->parents = $parents;
		foreach($parents as $parent) {
			$dependencyHelper = $this->createDependencyHelper($parent);
			$dependencyHelper->addOnChangeCallback(array($this, "submitButtonHandler"));

			if($parent instanceof DependentSelectBox)
				$parent->childs[] = $this;
			if(!self::$disableChilds && $this->isRoot($parent) && $parent instanceof SelectBox && $parent->getPrompt())
				throw new NotSupportedException('When first item on root is skipped, $disableChilds = false cant be used !');
		}
	}

	/**
	 * This method will be called when the component becomes attached to Form.
	 * @param  IComponent
	 * @return void
	 */
	protected function attached($form) {
		parent::attached($form);
		$this->processAttaching($form);
	}

	/**
	 * Process all attaching methods
	 * @param Form $form
	 */
	protected function processAttaching($form) {
		$this->autoSelectRootFirstItem();
		$this->initializeState($form);
	}

	/**
	 * Initialize state of control. "Disable" control or set value ...
	 * @param Form $form
	 */
	protected function initializeState($form) {
		if(self::$disableChilds && $this->hasAnyParentEmptyValue()) {
			$this->disableSelectBox();
		} else {
			$this->isDisabled = false;
			$this->setItemsFromCallback($form);
			if(self::$disableChilds && ($this->hasEmptyValue() || $this->leaveFirstEmpty)) {
				$this->addEmptyHeaderItem($this->hasEmptyValue());
			}
		}
	}

// </editor-fold>

// <editor-fold defaultstate="collapsed" desc="getters & setters & add & remove">

	/**
	 * Set value of selectbox
	 * @param mixed $value
	 * @param boolean $refreshTree Remake tree of components? (false only for internal system)
	 */
	public function setValue($value, $refreshTree = true) {
		if($value !== null && !$this->hasEmptyValue() && $this->hasAnyParentEmptyValue()) {
			throw new LogicException("Cant set value of dependent component when parent have no value ! (Control: '$this->name')");
		}
		try{ parent::setValue($value); } catch (Exception $e){}
		if($refreshTree && !$this->hasEmptyValue())
			$this->refresh(false);
		return $this;
	}

	/**
	 * Set value for "disabled" select box
	 * @param array array($key => $value)
	 * @return DependentSelectBox
	 */
	public function setDisabledValue($disabledValue = null) {
		$this->disabledValue = $disabledValue;
		if($this->isDependentSelectBoxDisabled())
			$this->disableSelectBox();
		return $this;
	}

	/**
	 * Sets default value for control and remake subtree of components
	 * @param mixed
	 */
	public function setDefaultValue($value) {
		if(!$this->hasEmptyValue() && $this->hasAnyParentEmptyValue()) {
			throw new LogicException("Cant set value of dependent component when parent have no value ! (Control: '$this->name')");
		}
		try{ parent::setDefaultValue($value); } catch (Exception $e){}
		$this->refresh(false);
		return $this;
	}

	/**
	 * Sets items from which to choose.
	 * @param  array
	 * @return DependentSelectBox  provides a fluent interface
	 */
	public function setItems( array $items, $useKeys = TRUE) {
		if(!$useKeys)
			throw new NotSupportedException("Working without keys not supported !");
		return parent::setItems($items, $useKeys);
	}

	/**
	 * Add callback which is called after submit
	 * Parameters passed to callback could be defined too
	 * @param mixed Parameters passed to callback
	 * @return DependentSelectBox  provides a fluent interface
	 */
	public function addOnSubmitCallback($callback, $parameter = null, $_ = null) {
		$params = func_get_args();
		unset($params[0]);
		$this->onSubmit[] = array(new \Nette\Callback($callback), $params);
		return $this;
	}

	public function setLeaveFirstEmpty($leaveFirstEmpty) {
		$this->leaveFirstEmpty = $leaveFirstEmpty;
		$this->refresh(false, false);
	}

	/**
	 * @return SelectBox[]|DependentSelectBox[]
	 */
	public function getDependentParents() {
		return $this->parents;
	}


// </editor-fold>

// <editor-fold defaultstate="collapsed" desc="signals">

	/**
	 * Handler which is called when form is submmited by button of this control
	 * @param SubmitButton $button
	 */
	public function submitButtonHandler($button) {
		$form = $button->getForm();

		foreach($this->onSubmit as $onSubmitItem) {
			list($callback, $params) = $onSubmitItem;
			$callback->invokeArgs($params);
		}

		if($this->hasAnyParentEmptyValue())
			return;

		if(self::$disableChilds) {
			$this->addEmptyHeaderItem();
			$this->disableAllChilds();
		} else {
			$this->setFirstItemSelected();
			$this->selectFirstItemForAllChilds();
		}

	}

// </editor-fold>

// <editor-fold defaultstate="collapsed" desc="tools & helpers">

	/**
	 * "Remake" tree of dependent components
	 * @param boolean $clearValue
	 * @param boolean $recursive Refresh all items in tree or only this item ?
	 */
	public function refresh($clearValue = false, $recursive = true) {
		$this->setPrompt(false);
		if($clearValue)
			$this->setValue(null, false);
		$this->initializeState($this->getForm());
		if($recursive) {
			foreach($this->childs as $child) {
				$child->refresh();
			}
		}
	}


	/**
	 * Create helper which creates submit buttons
	 * @param FormControl $control
	 * @return FormControlDependencyHelper
	 */
	protected function createDependencyHelper($control) {
		if($control instanceof FormControlDependencyHelper)
			return $control;
		$fcdh = new FormControlDependencyHelper($control);
		$fcdh->setButtonText("Load");
		return $fcdh;
	}

	/**
	 * Test whenever $this->autoSelectRootFirstItem is true and if parent is root SelectBox, select first item
	 */
	protected function autoSelectRootFirstItem() {
		if($this->autoSelectRootFirstItem) {
			foreach($this->parents as $parent) {
				if($this->isRoot($parent) && $parent->getValue() === null) {
					if(!($parent instanceof SelectBox))
						throw new InvalidArgumentException("When using 'autoSelectRootFirstItem = true', parent must be instance of SelectBox !");
					$items = $parent->getItems();
					if(!empty($items)) {
						$items = array_keys($items);
						$parent->setValue(reset($items));
					}
				}
			}
		}
	}

	/**
	 * Tests if any parent control have empty value
	 * @return booleam
	 */
	protected function hasAnyParentEmptyValue() {
		foreach($this->parents as $parent) {
			if($parent instanceof DependentSelectBox) {
				if($parent->hasEmptyValue())
					return true;
			} else {
				if(in_array($parent->getValue(), self::$emptyValues, true))
					return true;
			}
		}
		return false;
	}

	/**
	 * Tests if this control have empty value
	 * @return boolean
	 */
	protected function hasEmptyValue() {
		return $this->isDependentSelectBoxDisabled() || !$this->isSelected();
	}


	/**
	 * Load items from callback
	 * @param Form $form Form with values
	 */
	protected function setItemsFromCallback($form) {
		$data = $this->dataCallback->invoke($form, $this);
		if(!is_array($data))
			throw new InvalidArgumentException("Data must be array !");
		$this->setItems($data);
		if(!self::$disableChilds && $this->hasEmptyValue()) {
			$this->setFirstItemSelected();
		}
	}

	/**
	 * "Disable" all childs DependentSelectBox which are attached
	 */
	protected function disableAllChilds() {
		foreach($this->childs as $child) {
			$child->disableSelectBox();
			$child->disableAllChilds();
		}
	}

	/**
	 * "Disable" all childs DependentSelectBox which are attached
	 */
	protected function selectFirstItemForAllChilds() {
		foreach($this->childs as $child) {
			$child->setFirstItemSelected();
			$child->refresh(false, false);
			$child->selectFirstItemForAllChilds();
		}
	}

	/**
	 * "Disable" this control
	 */
	protected function disableSelectBox() {
		$this->isDisabled = true;
		if($this->getControlPrototype()->class == null)
			$this->getControlPrototype()->class = "";
		$this->getControlPrototype()->addClass(self::$disabledHtmlClass);
		if($this->disabledValue === null) {
			$this->setValue(null, false);
			$this->setItems(array());
			$this->setPrompt(self::$disabledItemTitle);
		} else {
			$keys = array_keys($this->disabledValue);
			$key = reset($keys);
			$this->setItems($this->disabledValue);
			$this->setValue($key, false);
		}
	}

	protected function isSelected() {
		return !$this->isDependentSelectBoxDisabled() && !in_array($this->getValue(), self::$emptyValues, true);
	}

	protected function isDependentSelectBoxDisabled() {
		return $this->isDisabled;
	}

	/**
	 * Is parent root SelectBox ?
	 * @param Control $control item to check
	 * @return boolean
	 */
	protected function isRoot($control) {
		return !($control instanceof DependentSelectBox);
	}

	/**
	 * Add empty header item and select him
	 */
	protected function addEmptyHeaderItem($selectFirst = true) {
		$this->setPrompt(self::$emptyValueTitle);
		if($selectFirst)
			$this->setFirstItemSelected();
	}

	/**
	 * Select first item
	 */
	protected function setFirstItemSelected() {
		$keys = array_keys($this->getItems());
		$key = reset($keys);
		$this->setValue($key, false);
	}

	public static function Container_prototype_addDependentSelectBox(FormContainer $obj, $name, $label, $parents, $dataCallback) {
		return $obj[$name] = new DependentSelectBox($label, $parents, $dataCallback);
	}

	public static function register($methodName = "addDependentSelectBox") {
		FormContainer::extensionMethod($methodName, "DependentSelectBox\DependentSelectBox::Container_prototype_addDependentSelectBox");
	}

// </editor-fold>

}
