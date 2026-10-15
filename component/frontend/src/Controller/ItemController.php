<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Site\Controller;


defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Mixin\ControllerEvents;
use Akeeba\Component\ARS\Administrator\Mixin\RunPluginsTrait;
use Akeeba\Component\ARS\Administrator\Mixin\TableAssertionTrait;
use Akeeba\Component\ARS\Administrator\Table\LogTable;
use Akeeba\Component\ARS\Site\Event\BeforeDownloadEvent;
use Akeeba\Component\ARS\Site\Mixin\ControllerCRIAccessTrait;
use Akeeba\Component\ARS\Site\Model\ItemModel;
use Exception;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\Input\Input;
use RuntimeException;

class ItemController extends BaseController
{
	use ControllerEvents;
	use ControllerCRIAccessTrait;
	use TableAssertionTrait;
	use RunPluginsTrait;

	public function __construct($config = [], ?MVCFactoryInterface $factory = null, ?CMSApplication $app = null, ?Input $input = null)
	{
		parent::__construct($config, $factory, $app, $input);

		$this->registerDefaultTask('download');
	}


	/**
	 * Downloads an item to the user's browser
	 *
	 * @throws Exception
	 */
	public function download(): void
	{
		$id = $this->input->getInt('item_id', null);

		// Get the page parameters
		/** @var ItemModel $model */
		$model = $this->getModel() ?: $this->getModel('Item', 'Site');

		// Log in a user if I have to
		$model->loginUser();

		try
		{
			$cParams = ComponentHelper::getParams('com_ars');
			$item    = $this->accessControlItem($id, false);

			$this->assertNotEmpty($item, 'Item not found or access denied');

			$release = $this->accessControlRelease($item->release_id, false);

			$this->assertNotEmpty($release, 'Release not found or access denied');

			$category = $this->accessControlCategory($release->category_id, false);

			$this->assertNotEmpty($category, 'Category not found or access denied');

			// Make sure this is a valid download item (link or pointing to an existing file)
			$model->preDownloadCheck($item, $category);

			// Let plugins refuse the download
			$dispatcher = $this->app->getDispatcher();
			PluginHelper::importPlugin('ars', null, true, $dispatcher);

			$event = new BeforeDownloadEvent($item, $release, $category, $this->app->getIdentity());
			$dispatcher->dispatch($event->getName(), $event);

			if ($event->isRefused())
			{
				$this->respondRefused($event, $model, $item->getId());
			}

			// Hit the item
			if ($cParams->get('hitcounting', 0))
			{
				$item->hit($item->getId());
			}

			// Log the download
			if ($cParams->get('log', 1))
			{
				/** @var LogTable $log */
				$log = $model->getTable('Log');

				$log->save([
						'item_id'    => $id,
						'authorized' => 1,
					]
				);
			}

			// Download the item
			$model->doDownload($item, $category);

			$this->app->close();
		}
		catch (Exception $e)
		{
			$item        ??= null;
			$effectiveId = ($item?->id ?? 0) ?: $id;
			$this->logFailedDownloadAttempt($effectiveId);

			if (empty($this->redirect))
			{
				$noAccessURL = ComponentHelper::getParams('com_ars')->get('no_access_url', '');

				if ($effectiveId && $item?->redirect_unauth && $item?->show_unauth_links)
				{
					$noAccessURL = $item->redirect_unauth;
				}

				// Do I need to route the redirection URL?
				if ((substr($noAccessURL, 0, 7) !== 'http://') && (substr($noAccessURL, 0, 7) !== 'https://'))
				{
					$noAccessURL = Route::_($noAccessURL);
				}

				if (!empty($noAccessURL))
				{
					$this->setRedirect($noAccessURL);
				}
			}

			if (!empty($this->redirect))
			{
				$model->logoutUser();

				$this->setRedirect($noAccessURL);

				return;
			}

			$model->logoutUser();

			throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403, $e);
		}
	}

	/**
	 * Answer a download a plugin refused with its HTTP status and message, as plain text, and stop.
	 *
	 * No redirect, unlike the other refusals: update clients follow redirects and would try to install the page.
	 *
	 * @param   BeforeDownloadEvent  $event
	 * @param   ItemModel            $model
	 * @param   int                  $id
	 *
	 * @throws Exception
	 */
	private function respondRefused(BeforeDownloadEvent $event, ItemModel $model, int $id): void
	{
		// A plugin's refusal is a business decision about a paying customer, not an attack: never ban for it
		$this->logFailedDownloadAttempt($id, false);
		$model->logoutUser();

		$this->app->setHeader('Status', (string) $event->getRefusalCode(), true);
		$this->app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
		$this->app->setHeader('Cache-Control', 'no-store', true);
		$this->app->sendHeaders();

		echo $event->getRefusalMessage();

		$this->app->close();
	}

	/**
	 * Log a failed download attempt for the given item ID
	 *
	 * @param   int   $id
	 * @param   bool  $ban  Whether the banUnauth option may ban the visitor. Not for a plugin's refusal.
	 *
	 * @throws Exception
	 */
	private function logFailedDownloadAttempt(int $id, bool $ban = true): void
	{
		$model   = $this->getModel();
		$cParams = ComponentHelper::getParams('com_ars');

		if ($cParams->get('log', 1))
		{
			$log = $model->getTable('Log');
			$log->save(
				[
					'item_id'    => $id,
					'authorized' => 0,
				]
			);
		}

		if ($ban && $cParams->get('banUnauth', 0))
		{
			$extraMessage = $id ? ('Item : ' . $id) : '';

			// Let's fire the system plugin event. If Admin Tools is installed, it will handle this and ban the user
			$this->triggerPluginEvent(
				'onAdminToolsThirdpartyException', [
					'external',
					Text::_('COM_ARS_BLOCKED_MESSAGE'),
					[$extraMessage],
					true,
				]
			);
		}
	}

}