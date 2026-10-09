<?php namespace ProcessWire;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\VerifySolutionOptions;
use AltchaOrg\Altcha\Algorithm\Pbkdf2;

/**
 * FormBuilder ALTCHA
 *
 * Adds an ALTCHA proof-of-work challenge (https://altcha.org) to FormBuilder forms.
 * Self-hosted, no cookies, no 3rd party. Can be enabled per form in the form editor
 * under: Actions > Filter spam > ALTCHA.
 *
 * The check runs only when the FormBuilder action "Filter spam" is enabled for the form.
 * Failed verifications always show an error so that humans can retry.
 *
 * Dependencies are installed module-local: run "composer install" in this directory.
 *
 * @author Philipp Michaelis (pmichaelis)
 * @license MIT
 * @link https://github.com/pmichaelis/FormBuilderAltcha
 *
 */
class FormBuilderAltcha extends WireData implements Module {

	public static function getModuleInfo() {
		return array(
			'title' => __('FormBuilder ALTCHA', __FILE__),
			'summary' => __('ALTCHA proof-of-work spam protection for FormBuilder, can be enabled per form under Actions > Filter spam.', __FILE__),
			'version' => '1.0.0',
			'author' => 'Philipp Michaelis (pmichaelis)',
			'href' => 'https://github.com/pmichaelis/FormBuilderAltcha',
			'icon' => 'shield',
			'autoload' => true,
			'singular' => true,
			'requires' => array('PHP>=8.1.0', 'ProcessWire>=3.0.200', 'FormBuilder'),
		);
	}

	/**
	 * Name of the property the settings are stored in on the FormBuilderForm
	 *
	 */
	const settingsKey = 'FormBuilderAltcha';

	/**
	 * Prefix for inputfield names in the form editor
	 *
	 */
	const prefix = 'fbal_';

	/**
	 * Name of the POST variable holding the ALTCHA payload
	 *
	 */
	const payloadName = 'altcha';

	/**
	 * URL path (relative to site root) that serves new challenges
	 *
	 */
	const challengePath = 'fb-altcha/challenge/';

	/**
	 * Spam type name passed to FormBuilder when spam is detected
	 *
	 */
	const spamType = 'altcha';

	/**
	 * PBKDF2 iterations per counter value
	 *
	 */
	const cost = 1000;

	/**
	 * Seconds a challenge stays valid
	 *
	 */
	const challengeLifetime = 3600;

	public function init() {
		$this->addHook('/' . self::challengePath, $this, 'hookChallenge');
		$this->addHookBefore('FormBuilderProcessor::renderReady', $this, 'hookRenderReady');
		$this->addHookAfter('FormBuilderProcessor::processInputIsSpam', $this, 'hookProcessInputIsSpam');
		$this->addHookAfter('ProcessFormBuilder::buildEditForm', $this, 'hookBuildEditForm');
		$this->addHookAfter('ProcessFormBuilder::executeSaveFormSettings', $this, 'hookSaveFormSettings');
	}

	/**
	 * Load composer dependencies
	 *
	 */
	protected function loadVendor() {
		$file = __DIR__ . '/vendor/autoload.php';
		if(!is_file($file)) {
			throw new WireException($this->_('FormBuilderAltcha: Dependencies are missing, please run "composer install" in the module directory.'));
		}
		require_once($file);
	}

	/**
	 * Default settings for a form
	 *
	 * @return array
	 *
	 */
	public function getDefaults() {
		return array(
			'enabled' => 0,
			'difficulty' => 'medium',
			'display' => 'standard',
			'language' => '',
			'log' => 1,
			'simulate' => '',
		);
	}

	/**
	 * Difficulty levels as [name => maximum counter]
	 *
	 * The client needs about (counter * cost) PBKDF2 iterations on average.
	 *
	 * @return array
	 *
	 */
	protected function getDifficulties() {
		return array(
			'low' => 1000,
			'medium' => 3000,
			'high' => 10000,
		);
	}

	/**
	 * Get settings for given form merged with defaults
	 *
	 * @param FormBuilderForm $fbForm
	 * @return array
	 *
	 */
	public function getSettings(FormBuilderForm $fbForm) {
		$settings = $fbForm->get(self::settingsKey);
		if(!is_array($settings)) $settings = array();
		return array_merge($this->getDefaults(), $settings);
	}

	/**
	 * Is ALTCHA active for the given processor?
	 *
	 * @param FormBuilderProcessor $processor
	 * @return array|false Settings when active, false when not
	 *
	 */
	protected function getActiveSettings(FormBuilderProcessor $processor) {
		if(!($processor->saveFlags & FormBuilderProcessor::saveFlagFilterSpam)) return false;
		$fbForm = $processor->getFbForm();
		if(!$fbForm) return false;
		$settings = $this->getSettings($fbForm);
		return empty($settings['enabled']) ? false : $settings;
	}

	/**
	 * Get the simulation mode that applies to the current user
	 *
	 * Simulations only apply to logged-in users with FormBuilder permission, so that
	 * a forgotten test setting never blocks real visitors.
	 *
	 * @param array $settings
	 * @return string Blank, 'mockError' or 'server'
	 *
	 */
	protected function getSimulation(array $settings) {
		if(empty($settings['simulate'])) return '';
		$user = $this->wire()->user;
		if(!$user->isLoggedin() || !$user->hasPermission('form-builder')) return '';
		return $settings['simulate'];
	}

	/*** ALTCHA *************************************************************************/

	/**
	 * @return Altcha
	 *
	 */
	protected function altcha() {
		$this->loadVendor();
		$salt = $this->wire()->config->userAuthSalt . __CLASS__;
		return new Altcha(
			hmacSignatureSecret: hash_hmac('sha256', 'signature', $salt),
			hmacKeySignatureSecret: hash_hmac('sha256', 'key-signature', $salt),
		);
	}

	/**
	 * Serve a new challenge as JSON (URL hook)
	 *
	 * @param HookEvent $event
	 * @return string
	 *
	 */
	public function hookChallenge(HookEvent $event) {
		$difficulty = 'medium';
		$formName = $this->wire()->input->get->name('form');
		if($formName) {
			/** @var FormBuilder $forms */
			$forms = $this->wire('forms');
			$fbForm = $forms->load($formName);
			if($fbForm instanceof FormBuilderForm) $difficulty = $this->getSettings($fbForm)['difficulty'];
		}
		$difficulties = $this->getDifficulties();
		$maxCounter = isset($difficulties[$difficulty]) ? $difficulties[$difficulty] : $difficulties['medium'];

		$challenge = $this->altcha()->createChallenge(new CreateChallengeOptions(
			algorithm: new Pbkdf2(),
			cost: self::cost,
			counter: random_int((int) ($maxCounter / 2), $maxCounter),
			expiresAt: time() + self::challengeLifetime,
		));

		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, max-age=0');
		return $challenge->toJson();
	}

	/**
	 * Verify the posted ALTCHA payload
	 *
	 * @return string Reason when invalid, blank string when valid
	 *
	 */
	protected function verifyAltcha() {
		$payload = (string) $this->wire()->input->post(self::payloadName);
		if($payload === '') return $this->_('ALTCHA: solution missing');

		try {
			$result = $this->altcha()->verifySolution(new VerifySolutionOptions(
				algorithm: new Pbkdf2(),
				payload: $payload,
			));
		} catch(\Throwable $e) {
			return $this->_('ALTCHA: invalid solution');
		}
		if($result->expired) return $this->_('ALTCHA: challenge expired');
		if(!$result->verified) return $this->_('ALTCHA: invalid solution');

		// every solved challenge may be used only once
		$data = json_decode((string) base64_decode($payload, true), true);
		$key = isset($data['challenge']['signature']) ? (string) $data['challenge']['signature'] : $payload;
		$key = 'used-' . sha1($key);
		$cache = $this->wire()->cache;
		if($cache->getFor($this, $key)) return $this->_('ALTCHA: solution already used');
		$cache->saveFor($this, $key, 1, self::challengeLifetime);

		return '';
	}

	/*** FRONTEND ***********************************************************************/

	/**
	 * Add ALTCHA widget above the submit button before rendering
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookRenderReady(HookEvent $event) {
		/** @var FormBuilderProcessor $processor */
		$processor = $event->object;
		/** @var InputfieldForm $form */
		$form = $event->arguments(0);
		$settings = $this->getActiveSettings($processor);
		if(!$settings) return;

		$config = $this->wire()->config;
		$sanitizer = $this->wire()->sanitizer;
		$formName = $processor->getFbForm()->name;
		$challengeUrl = $config->urls->root . self::challengePath . '?form=' . urlencode($formName);
		$scriptUrl = $config->urls($this) . 'assets/altcha.min.js?v=3.3.0';
		$display = $settings['display'] === 'floating' ? 'floating' : 'standard';
		$auto = $display === 'floating' ? 'onsubmit' : 'onfocus';
		$language = $settings['language'] ? $settings['language'] : $this->_x('en', 'widget-language'); // ISO code of the ALTCHA widget language, i.e. "de"
		$language = $sanitizer->name($language);
		$widgetConfig = array();
		if($this->getSimulation($settings) === 'mockError') $widgetConfig['mockError'] = true;

		$markup =
			"<script type='module' src='$scriptUrl'></script>" .
			"<altcha-widget class='FormBuilderAltcha' " .
				"challenge='" . $sanitizer->entities($challengeUrl) . "' " .
				"name='" . self::payloadName . "' auto='$auto' display='$display'" .
				($language ? " language='$language'" : '') .
				(count($widgetConfig) ? " configuration='" . $sanitizer->entities(json_encode($widgetConfig)) . "'" : '') .
			"></altcha-widget>";

		foreach($form->getAll() as $f) {
			if(!$f instanceof InputfieldSubmit) continue;
			$f->prependMarkup = $markup . $f->prependMarkup;
			return;
		}
		$form->appendMarkup .= $markup;
	}

	/**
	 * Verify ALTCHA after FormBuilder's own spam checks
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookProcessInputIsSpam(HookEvent $event) {
		if($event->return) return; // already detected as spam
		$processed = $event->arguments(1);
		if(!$processed) return; // only check once input has been processed

		/** @var FormBuilderProcessor $processor */
		$processor = $event->object;
		/** @var InputfieldForm $form */
		$form = $event->arguments(0);
		$settings = $this->getActiveSettings($processor);
		if(!$settings) return;

		$simulation = $this->getSimulation($settings);
		$reason = $simulation === 'server' ? $this->_('ALTCHA: simulated failure') : $this->verifyAltcha();
		if(!$reason) return;
		if($simulation) $reason .= ' [' . $this->_('simulation') . ']';

		// humans can fail too (no JavaScript, expired), so always tell them and let them retry
		$processor->addError($this->_('The spam check failed. Please confirm the verification above the submit button and submit the form again.'));

		if(!empty($settings['log'])) {
			$this->wire()->log->save('form-builder-altcha', $processor->getFbForm()->name . ": $reason");
		}

		$processor->processInputSpammed($form, self::spamType);
		$event->return = self::spamType;
	}

	/*** ADMIN **************************************************************************/

	/**
	 * Add settings to the "Filter spam" fieldset in the form editor (Actions tab)
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookBuildEditForm(HookEvent $event) {
		/** @var InputfieldForm $editForm */
		$editForm = $event->return;
		$fieldsetSpam = null;
		foreach($editForm->getAll(array('withWrappers' => true)) as $f) {
			if($f->attr('id') !== 'fieldsetSpam') continue;
			$fieldsetSpam = $f;
			break;
		}
		if(!$fieldsetSpam instanceof InputfieldWrapper) return;

		/** @var FormBuilder $forms */
		$forms = $this->wire('forms');
		$fbForm = $forms->load((int) $event->arguments(0));
		if(!$fbForm instanceof FormBuilderForm) return;

		$modules = $this->wire()->modules;
		$settings = $this->getSettings($fbForm);
		$p = self::prefix;
		$showIf = "{$p}enabled=1";

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->attr('id+name', "{$p}fieldset");
		$fs->label = $this->_('ALTCHA');
		$fs->description = $this->_('Before submitting, the browser invisibly solves a small computational challenge. Runs entirely on this server, without cookies and without third parties.');
		$fs->icon = 'shield';
		$fs->collapsed = empty($settings['enabled']) ? Inputfield::collapsedYes : Inputfield::collapsedNo;
		$fieldsetSpam->add($fs);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', "{$p}enabled");
		$f->label = $this->_('Enable ALTCHA');
		$f->label2 = $this->_('Protect submissions with ALTCHA');
		$f->description = $this->_('If the check fails, the submitter always gets an error message and can try again.');
		if(!empty($settings['enabled'])) $f->attr('checked', 'checked');
		$fs->add($f);

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', "{$p}difficulty");
		$f->label = $this->_('Difficulty');
		$f->addOption('low', $this->_('Low (approx. 0.2 s)'));
		$f->addOption('medium', $this->_('Medium (approx. 0.5 s)'));
		$f->addOption('high', $this->_('High (approx. 2 s)'));
		$f->attr('value', $settings['difficulty']);
		$f->required = true;
		$f->notes = $this->_('Computing time on a current computer, about 3 to 5 times longer on older smartphones. With the checkbox display, the computation runs in the background while the form is being filled in.');
		$f->columnWidth = 33;
		$f->showIf = $showIf;
		$fs->add($f);

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', "{$p}display");
		$f->label = $this->_('Display');
		$f->addOption('standard', $this->_('Checkbox above the submit button (computes from the first input)'));
		$f->addOption('floating', $this->_('Popup on submit (computes on submit only)'));
		$f->attr('value', $settings['display']);
		$f->required = true;
		$f->columnWidth = 34;
		$f->showIf = $showIf;
		$fs->add($f);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', "{$p}language");
		$f->label = $this->_('Widget language');
		$f->description = $this->_('ISO code, e.g. de or en. Leave blank to use the language of the current page.');
		$f->attr('value', $settings['language']);
		$f->columnWidth = 33;
		$f->showIf = $showIf;
		$fs->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', "{$p}log");
		$f->label = $this->_('Log failed checks');
		$f->description = $this->_('Writes form and reason to Setup > Logs > form-builder-altcha.');
		if(!empty($settings['log'])) $f->attr('checked', 'checked');
		$f->columnWidth = 50;
		$f->showIf = $showIf;
		$fs->add($f);

		/** @var InputfieldRadios $f */
		$f = $modules->get('InputfieldRadios');
		$f->attr('name', "{$p}simulate");
		$f->label = $this->_('Simulate failure (test mode)');
		$f->description = $this->_('Tests the form behavior and the log without a real spam attack. Only applies to logged-in users with FormBuilder permission, visitors are not affected.');
		$f->addOption('', $this->_('Off'));
		$f->addOption('mockError', $this->_('Widget error (mockError, as in the ALTCHA playground)'));
		$f->addOption('server', $this->_('Server rejects the solution'));
		$f->notes = $this->_('With “Popup on submit”, a widget error prevents submitting entirely; use “Server rejects the solution” to test the error message and the log.');
		$f->attr('value', $settings['simulate']);
		$f->columnWidth = 50;
		$f->showIf = $showIf;
		$fs->add($f);

		if(!empty($settings['enabled']) && !empty($settings['simulate'])) {
			$fs->label .= ' – ' . $this->_('test mode active');
		}
	}

	/**
	 * Save settings from the form editor
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookSaveFormSettings(HookEvent $event) {
		/** @var FormBuilderForm $fbForm */
		$fbForm = $event->arguments(0);
		/** @var InputfieldForm $editForm */
		$editForm = $event->arguments(1);
		$p = self::prefix;
		if(!$editForm->getChildByName("{$p}enabled")) return;

		$settings = array();
		foreach(array('enabled', 'log') as $name) {
			$settings[$name] = $editForm->getChildByName($p . $name)->attr('checked') ? 1 : 0;
		}
		foreach(array('difficulty', 'display', 'language', 'simulate') as $name) {
			$settings[$name] = (string) $editForm->getChildByName($p . $name)->attr('value');
		}

		$fbForm->set(self::settingsKey, $settings);
	}
}
