<?php
App::uses('CrudEventSubject', 'Crud.Controller/Event');

/**
 * Crud component
 *
 * Handles the automatic transformation of HTTP requests to API responses
 *
 * Copyright 2010-2012, Nodes ApS. (http://www.nodesagency.com/)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @see https://wiki.ournodes.com/display/platform/Api+Plugin
 * @see http://book.cakephp.org/2.0/en/controllers/components.html#Component
 * @copyright Nodes ApS, 2012
 */
class CrudComponent extends Component {

	/**
	 * Reference to a Session component
	 *
	 * @cakephp
	 * @var array
	 */
	public $components = array('Session');

	/**
	 * The current controller action
	 *
	 * @platform
	 * @var string
	 */
	protected $_action;

	/**
	 * Reference to the current controller
	 *
	 * @platform
	 * @var Controller
	 */
	protected $_controller;

	/**
	 * Reference to the current request
	 *
	 * @platform
	 * @var CakeRequest
	 */
	protected $_request;

	/**
	 * Reference to the current event manager
	 *
	 * @platform
	 * @var CakeEventManager
	 */
	protected $_eventManager;

	/**
	* Cached property for Controller::modelClass
	*
	* @platform
	* @var string
	*/
	protected $_modelName;

	/**
	* Cached propety for the current Controller::modelClass instance
	*
	* @platform
	* @var Model
	*/
	protected $_model;

	/**
	* All emitted events will be prefixed with this property value
	*
	* @platform
	* @var string
	*/
	protected $_eventPrefix = 'Crud';

	/**
	 * A map of the controller action and what CRUD action we should call
	 *
	 * By default it supports non-prefix and admin_ prefixed routes
	 *
	 * @platform
	 * @var array
	 */
	protected $_actionMap = array(
		'index'			=> 'index',
		'add'			=> 'add',
		'edit'			=> 'edit',
		'view'			=> 'view',
		'delete'		=> 'delete',

		'admin_index'	=> 'index',
		'admin_add'		=> 'add',
		'admin_edit'	=> 'edit',
		'admin_view'	=> 'view',
		'admin_delete'	=> 'delete'
	);

	/**
	 * A map of the controller action and the view to render
	 *
	 * By default it supports non-prefix and admin_ prefixed routes
	 *
	 * @platform
	 * @var array
	 */
	protected $_viewMap = array(
		'index'			=> 'index',
		'add'			=> 'form',
		'edit'			=> 'form',
		'view'			=> 'view',

		'admin_index'	=> 'admin_index',
		'admin_add'		=> 'admin_form',
		'admin_edit'	=> 'admin_form',
		'admin_view'	=> 'admin_view'
	);

	/**
	 * Make sure to update the list of known controller methods before startup is called
	 *
	 * The reason for this is that if we don't, the Auth component won't execute any callbacks on the controller
	 * like isAuthorized
	 *
	 * @param Controller $controller
	 * @return void
	 */
	public function initialize(Controller $controller) {
		if ($controller->name == 'CakeError') {
			return true;
		}

		$this->_controller = $controller;
		$this->_controller->methods = array_keys(array_flip($this->_controller->methods) + array_flip($this->settings['actions']));

		// Create some easy accessible class properties
		$this->_action		= $this->_controller->request->action;
		$this->_request		= $this->_controller->request;
		$this->_eventManager= $this->_controller->getEventManager();

		if (!isset($this->_controller->dispatchComponents)) {
			$this->_controller->dispatchComponents = array();
		}

		$name = str_replace('Component', '', get_class($this));
		$this->_controller->dispatchComponents[$name] = true;
	}

	/**
	* Execute a Crud action
	*
	* @platform
	* @param string $action		The CRUD action
	* @param array $arguments	List of arguments to pass to the CRUD action (Usually an ID to edit / delete)
	* @return void
	*/
	public function executeAction($action = null, $args = array()) {
		$view = $action = $action ?: $this->_action;
		$this->_setModelProperties();

		$this->_trigger('init');

		// Test if action is mapped
		if (empty($this->_actionMap[$action])) {
			throw new Exception(sprintf('Action "%s" has not been mapped', $action));
		}

		// Change the view file before executing the CRUD action (so mapActionView works)
		if (array_key_exists($action, $this->_viewMap)) {
			$view = $this->_viewMap[$action];
			$this->_controller->view = $view;
		}

		try {
			// Execute the default action, inside this component
			$response = call_user_func_array(array($this, '_' . $this->_actionMap[$action] . 'Action'), $args);
			if ($response instanceof CakeResponse) {
				return $response;
			}
		} catch (Exception $e) {
			if (isset($e->response)) {
				return $e->response;
			}
			throw $e;
		}

		// Render the file based on action name
		return $this->_controller->response = $this->_controller->render($view);
	}

	protected function _setModelProperties() {
		$this->_modelName	= $this->_controller->modelClass;
		$this->_model		= $this->_controller->{$this->_modelName};
	}

	/**
	 * Triggers a Crud event by creating a new subject and filling it with $data
	 * if $data is an instance of CrudEventSubject it will be reused as the subject
	 * objec for this event.
	 *
	 * If Event listenrs return a CakeResponse object, the this methid will throw an
	 * exeption and fill a 'response' property on it with a referente to the response
	 * object.
	 *
	 * @throws Exception if any event listener return a CakeResponse object
	 * @return CrudEventSubject
	 **/
	protected function _trigger($eventName, $data = array()) {
		$subject = $data instanceof CrudEventSubject ? $data : $this->_getSubject($data);
		$event = new CakeEvent($this->_eventPrefix . '.' . $eventName, $subject);
		$this->_eventManager->dispatch($event);
		if ($event->result instanceof CakeResponse) {
			$exception = new Exception();
			$exception->response = $event->result;
			throw $exception;
		}

		$subject->stopped = true;
		if ($event->isStopped()) {
			$subject->stopped = false;
		}
		return $subject;
	}

	/**
	 * Enable a CRUD action
	 *
	 * @platform
	 * @param string $action The action to enable
	 * @return void
	 */
	public function enableAction($action) {
		$pos = array_search($action, $this->settings['actions']);
		if (false === $pos) {
			$this->settings['actions'][] = $action;
		}

		$pos = array_search($action, $this->_controller->methods);
		if (false === $pos) {
			$this->_controller->methods[] = $action;
		}
	}

	/**
	 * Disable a CRUD action
	 *
	 * @platform
	 * @param string $action The action to disable
	 * @return void
	 */
	public function disableAction($action) {
		$pos = array_search($action, $this->settings['actions']);
		if (false !== $pos) {
			unset($this->settings['actions'][$pos]);
		}

		$pos = array_search($action, $this->_controller->methods);
		if (false !== $pos) {
			unset($this->_controller->methods[$pos]);
		}
	}

	/**
	 * Map the view file to use for a controller action
	 *
	 * To map multiple action views in one go pass an array as first argument and no second argument
	 *
	 * @platform
	 * @param string|array $action
	 * @param string $view
	 * @return void
	 */
	public function mapActionView($action, $view = null) {
		if (is_array($action)) {
			$this->_viewMap = $this->_viewMap + $action;
			return;
		}

		$this->_viewMap[$action] = $view;
	}

	/**
	 * Map action to a internal request type
	 *
	 * @param string $action The Controller action to fake
	 * @param string $type one of the CRUD events (index, add, edit, delete, view)
	 * @param boolean $enable Should the mapping be enabled right away?
	 * @return void
	 */
	public function mapAction($action, $type, $enable = true) {
		$this->_actionMap[$action] = $type;
		if ($enable) {
			$this->enableAction($action);
		}
	}

	/**
	 * Check if a CRUD action has been mapped (aka should be handled by CRUD component)
	 *
	 * @param string|null $action If null, use the current action
	 * @return boolean
	 */
	public function isActionMapped($action = null) {
		if (empty($action)) {
			$action = $this->_action;
		}

		return false !== array_search($action, $this->settings['actions']);
	}

	/**
	 * Attaches an event listener function to the controller for Crud Events
	 *
	 * @param string $event Name of the Crud Event you want to attach to controller
	 * @param callback $callback callable method or closure to be executed on event
	 * @return void
	 **/
	public function on($event, $callback) {
		if (!strpos($event, '.')) {
			$event = $this->_eventPrefix . '.' . $event;
		}
		$this->_controller->getEventManager()->attach($callback, $event);
	}

	/**
	 * Helper method to get the passed ID to an action
	 *
	 * @platform
	 * @return string
	 */
	public function getIdFromRequest() {
		if (empty($this->_request->params['pass'][0])) {
			return null;
		}
		return $this->_request->params['pass'][0];
	}

	/**
	 * Create a CakeEvent subject with the required properties
	 *
	 * @param array $additional Additional properties for the subject
	 * @return CrudEventSubject
	 */
	protected function _getSubject($additional = array()) {
		$subject				= new CrudEventSubject();
		$subject->crud			= $this;
		$subject->controller	= $this->_controller;
		$subject->model			= $this->_model;
		$subject->modelClass	= $this->_modelName;
		$subject->action		= $this->_action;
		$subject->request		= $this->_request;
		$subject->response		= $this->_controller->response;
		$subject->set($additional);

		return $subject;
	}

	/**
	 * Generic index action
	 *
	 * Triggers the following callbacks
	 *	- Crud.init
	 *	- Crud.beforePaginate
	 *	- Crud.afterPaginate
	 *	- Crud.beforeRender
	 *
	 * @platform
	 * @param string $id
	 * @return void
	 */
	protected function _indexAction() {
		$this->_trigger('beforePaginate');

		$items = $this->_controller->paginate($this->_model);

		$subject = $this->_trigger('afterPaginate', compact('items'));
		$items = $subject->items;

		$this->_controller->set(compact('items'));
		$this->_trigger('beforeRender');
	}

	/**
	 * Generic add action
	 *
	 * Triggers the following callbacks
	 *	- Crud.init
	 *	- Crud.beforeSave
	 *	- Crud.afterSave
	 *	- Crud.beforeRender
	 *
	 * @platform
	 * @param string $id
	 * @return void
	 */
	protected function _addAction() {
		if ($this->_request->is('post')) {
			$this->_trigger('beforeSave');
			if ($this->_model->saveAll($this->_request->data, array('validate' => 'first', 'atomic' => true))) {
				$this->_setFlash(sprintf('Succesfully created %s', Inflector::humanize($this->_modelName)), 'success');
				$subject = $this->_trigger('afterSave', array('success' => true, 'id' => $this->_model->id));
				$this->_redirect($subject, array('action' => 'index'));
			} else {
				$this->_setFlash(sprintf('Could not create %s', Inflector::humanize($this->_modelName)), 'error');
				$this->_trigger('afterSave', array('success' => false));
				// Make sure to merge any changed data in the model into the post data
				$this->_request->data = Set::merge($this->_request->data, $this->_model->data);
			}
		}

		$this->_trigger('beforeRender', array('success' => false));
	}

	/**
	 * Generic edit action
	 *
	 * Triggers the following callbacks
	 *	- Crud.init
	 *	- Crud.beforeSave
	 *	- Crud.afterSave
	 *	- Crud.beforeFind
	 *	- Crud.recordNotFound
	 *	- Crud.afterFind
	 *	- Crud.beforeRender
	 *
	 * @platform
	 * @param string $id
	 * @return void
	 */
	protected function _editAction($id = null) {
		if (empty($id)) {
			$id = $this->getIdFromRequest();
		}
		$this->_validateId($id);

		if ($this->_request->is('put')) {
			$this->_trigger('beforeSave', compact('id'));
			if ($this->_model->saveAll($this->_request->data, array('validate' => 'first', 'atomic' => true))) {
				$this->_setFlash(sprintf('%s was succesfully updated', ucfirst(Inflector::humanize($this->_modelName))), 'success');
				$subject = $this->_trigger('afterSave', array('id' => $id, 'success' => true));
				$this->_redirect($subject, array('action' => 'index'));
			} else {
				$this->_setFlash(sprintf('Could not update %s', Inflector::humanize($this->_modelName)), 'error');
				$this->_trigger('afterSave' ,array('id' => $id, 'success' => false));
			}
		} else {
			$query = array();
			$query['conditions'] = array($this->_model->escapeField() => $id);
			$subject = $this->_trigger('beforeFind', compact('query'));
			$query = $subject->query;

			$this->_request->data = $this->_model->find('first', $query);
			if (empty($this->_request->data)) {
				$subject = $this->_trigger('recordNotFound', compact('id'));
				$this->_setFlash(sprintf('Could not find %s', Inflector::humanize($this->_modelName)), 'error');
				$this->_redirect($subject, array('action' => 'index'));
			}

			$this->_trigger('afterFind', compact('id'));

			// Make sure to merge any changed data in the model into the post data
			$this->_request->data = Set::merge($this->_request->data, $this->_model->data);
		}

		// Trigger a beforeRender
		$this->_trigger('beforeRender');
	}

	/**
	 * Generic view action
	 *
	 * Triggers the following callbacks
	 *	- Crud.init
	 *	- Crud.beforeFind
	 *	- Crud.recordNotFound
	 *	- Crud.afterFind
	 *	- Crud.beforeRender
	 *
	 * @platform
	 * @param string $id
	 * @return void
	 */
	protected function _viewAction($id = null) {
		if (empty($id)) {
			$id = $this->getIdFromRequest();
		}

		$this->_validateId($id);

		// Build conditions
		$query = array();
		$query['conditions'] = array($this->_model->escapeField() => $id);
		$subject = $this->_trigger('beforeFind', compact('id', 'query'));
		$query = $subject->query;

		// Try and find the database record
		$item = $this->_model->find('first', $query);

		// We could not find any record match the conditions in query
		if (empty($item)) {
			$subject = $this->_trigger('recordNotFound', compact('id'));
			$this->_setFlash(sprintf('Could not find %s', Inflector::humanize($this->_modelName)), 'error');
			$this->_redirect($subject, array('action' => 'index'));
		}

		// We found a record, trigger an afterFind
		$subject = $this->_trigger('afterFind', compact('id', 'item'));
		$item = $subject->item;

		// Push it to the view
		$this->_controller->set(compact('item'));

		// Trigger a beforeRender
		$this->_trigger('beforeRender', compact('id', 'item'));
	}

	/**
	 * Generic delete action
	 *
	 * Triggers the following callbacks
	 *	- beforeFind
	 *	- recordNotFound
	 *	- beforeDelete
	 *	- afterDelete
	 *
	 * @platform
	 * @param string $id
	 * @return void
	 */
	protected function _deleteAction($id = null) {
		if (empty($id)) {
			$id = $this->getIdFromRequest();
		}

		$this->_validateId($id);
		$query = array();
		$query['conditions'] = array($this->_model->escapeField() => $id);
		$subject = $this->_trigger('beforeFind', compact('id', 'query'));
		$query = $subject->query;

		$count = $this->_model->find('count', $query);
		if (empty($count)) {
			$subject = $this->_trigger('recordNotFound', compact('id'));
			$this->_setFlash(sprintf('Could not find %s', Inflector::humanize($this->_modelName)), 'error');
			$this->_redirect($subject, array('action' => 'index'));
		}

		$subject = $this->_trigger('beforeDelete', compact('id'));
		if ($subject->stopped) {
			$this->_setFlash(sprintf('Could not delete %s', Inflector::humanize($this->_modelName)), 'error');
			$this->_redirect($subject, array('action' => 'index'));
		}

		if ($this->_request->is('delete')) {
			if ($this->_model->delete($id)) {
				$this->_setFlash(sprintf('Successfully deleted %s', Inflector::humanize($this->_modelName)), 'success');
				$subject = $this->_trigger('afterDelete', array('id' => $id, 'success' => true));
			} else {
				$this->_setFlash(sprintf('Could not delete %s', Inflector::humanize($this->_modelName)), 'error');
				$subject = $this->_trigger('afterDelete', array('id' => $id, 'success' => false));
			}
		} else {
			$this->_setFlash(sprintf('Invalid HTTP request', Inflector::humanize($this->_modelName)), 'error');
		}

		$this->_redirect($subject, $this->_controller->referer(array('action' => 'index')));
	}

	/**
	 * Called for all redirects inside CRUD
	 *
	 * @param array|null $url
	 * @return void
	 */
	protected function _redirect($subject, $url = null) {
		if (!empty($this->_request->data['redirect_url'])) {
			$url = $this->_request->data['redirect_url'];
		} elseif (!empty($this->_request->query['redirect_url'])) {
			$url = $this->_request->query['redirect_url'];
		}

		$subject->url = $url;
		$subject = $this->_trigger('beforeRedirect', $subject);
		$url = $subject->url;

		$this->_controller->redirect($url);
	}

	/**
	* Wrapper for Session::setFlash
	*
	* Each param can be modified in setFlash $subject->{$property}
	*
	* @param string $message Message to be flashed
	* @param string $element Element to wrap flash message in.
	* @param array $params Parameters to be sent to layout as view variables
	* @param string $key Message key, default is 'flash'
	* @return void
	*/
	protected function _setFlash($message, $element = 'default', $params = array(), $key = 'flash') {
		$subject = $this->_trigger('setFlash', compact('message', 'element', 'params', 'key'));
		$this->Session->setFlash($subject->message, $subject->element, $subject->params, $subject->key);
	}

	/**
	 * Is the passed ID valid ?
	 *
	 * By default we asume you want to validate an UUID string
	 *
	 * Change the validateId settings key to "integer" for is_numeric check instead
	 *
	 * @return boolean
	 */
	protected function _validateId($id, $type = null) {
		if (empty($type)) {
			if (isset($this->settings['validateId'])) {
				$type = $this->settings['validateId'];
			} else {
				$type = 'uuid';
			}
		}
		if (!$type) {
			return true;
		} elseif ($type === 'uuid') {
			$valid = Validation::uuid($id);
		} else {
			$valid = is_numeric($id);
		}

		if ($valid) {
			return true;
		}

		$subject = $this->_trigger('invalidId', compact('id'));
		$this->_setFlash('Invalid id', 'error');
		$this->_redirect($subject, $this->_controller->referer());

		return false;
	}
}
