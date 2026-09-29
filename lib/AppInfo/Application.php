<?php

declare(strict_types=1);

namespace OCA\Arcade\AppInfo;

use OCA\Arcade\BackgroundJob\CleanupSweep;
use OCA\Arcade\BackgroundJob\FetchThumbnails;
use OCA\Arcade\BackgroundJob\RefreshMetadata;
use OCA\Arcade\Capabilities;
use OCA\Arcade\CoreMap;
use OCA\Arcade\Listener\CleanupListener;
use OCA\Arcade\Listener\CSPListener;
use OCA\Arcade\Listener\LoadSidebarListener;
use OCA\Arcade\Listener\LoadViewerListener;
use OCA\Arcade\Listener\MetadataListener;
use OCA\Arcade\Notification\Notifier;
use OCA\Arcade\Preview\RomPreview;
use OCA\Arcade\Settings\DeclarativeAdmin;
use OCA\Arcade\SetupChecks\ArcadeSetupCheck;
use OCA\Files\Event\LoadSidebar;
use OCA\Viewer\Event\LoadViewer;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\FilesMetadata\Event\MetadataBackgroundEvent;
use OCP\FilesMetadata\Event\MetadataLiveEvent;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\Util;

class Application extends App implements IBootstrap {
	public const APP_ID = 'arcade';

	/**
	 * The prefix each cache of the app lives under. Named here rather than
	 * spelled out where they are opened, so a cache cannot be added without
	 * the list below -- and so the cleanup -- knowing about it.
	 */
	public const CACHE_LIBRARY = self::APP_ID . '_library';
	public const CACHE_FETCH = self::APP_ID . '_fetch';
	public const CACHE_PREVIEW = self::APP_ID . '_preview';

	/**
	 * The caches the app fills, and the jobs it runs -- queued and
	 * recurring alike. Both are dropped when the app is disabled or
	 * removed, so a new one of either belongs in this list and nowhere
	 * else; a recurring job is also declared in info.xml, which is what
	 * brings it back when the app is enabled again.
	 */
	public const CACHES = [self::CACHE_LIBRARY, self::CACHE_FETCH, self::CACHE_PREVIEW];
	public const JOBS = [FetchThumbnails::class, RefreshMetadata::class, CleanupSweep::class];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CSPListener::class);
		// Save states have no owner of their own, so they are removed with
		// the game or the user they belong to.
		$context->registerEventListener(NodeDeletedEvent::class, CleanupListener::class);
		$context->registerEventListener(UserDeletedEvent::class, CleanupListener::class);
		// Unconditional, though the classes belong to other apps: ::class is
		// only a string, and a listener for an event nobody dispatches never
		// runs. A class_exists() guard here would misfire -- apps register
		// alphabetically, each right after its own autoloader, so the Files
		// and Viewer classes are not loadable yet when "arcade" registers.
		$context->registerEventListener(LoadViewer::class, LoadViewerListener::class);
		// What the app knows about a ROM, as a tab of the Files sidebar.
		$context->registerEventListener(LoadSidebar::class, LoadSidebarListener::class);
		// What is true of the ROM itself is filed with the file.
		$context->registerEventListener(MetadataLiveEvent::class, MetadataListener::class);
		$context->registerEventListener(MetadataBackgroundEvent::class, MetadataListener::class);
		// Box art becomes the preview of a ROM, in the Files app and
		// anywhere else Nextcloud shows one.
		$context->registerPreviewProvider(RomPreview::class, RomPreview::mimeTypeRegex());
		// A box art run finishes long after the player has walked away
		// from the settings page; the notification bell is what remains.
		$context->registerNotifierService(Notifier::class);
		// Games are only recognized by background jobs, so a server that
		// never runs them is told so where an administrator will look.
		$context->registerSetupCheck(ArcadeSetupCheck::class);
		// The instance-only scalars are a form the server itself renders
		// and saves; the app only says what they are.
		$context->registerDeclarativeSettings(DeclarativeAdmin::class);
		// What a client can learn about the app without asking it for
		// anything. Registered here rather than in info.xml so that it
		// goes when the app does: the server only calls register() for
		// apps that are enabled, so a disabled Arcade is simply absent
		// from /ocs/v2.php/cloud/capabilities, with nothing to clean up.
		$context->registerCapability(Capabilities::class);
	}

	public function boot(IBootContext $context): void {
		// Emptying the trash, or one item being expunged from it, is only
		// ever told through this legacy signal: NodeDeletedEvent does not
		// fire for paths in the trash. Saves survive the trash, so this is
		// where they finally go.
		$context->injectFn(function (CleanupListener $listener): void {
			Util::connectHook('\OCP\Trashbin', 'preDelete', $listener, 'trashItemDeleted');
		});
		$context->injectFn(function (IMimeTypeDetector $detector): void {
			// registerType() lives on the implementation, not on the public
			// interface, so make sure it is there. Without it ROMs are still
			// recognized by their file extension, only the mimetype-based
			// integrations are lost.
			if (!method_exists($detector, 'registerType')) {
				return;
			}
			// Load the default mappings first, as registering a type before
			// they are loaded would prevent them from being loaded at all.
			$mappings = $detector->getAllMappings();
			foreach (CoreMap::extensionMimeMap() as $extension => $mime) {
				// An extension the server already knows is left alone:
				// ".md" is Markdown to Nextcloud, and claiming it here
				// would retype every note on the instance.
				if (isset($mappings[$extension]) && !in_array($mime, $mappings[$extension], true)) {
					continue;
				}
				$detector->registerType($extension, $mime);
			}
		});
	}
}
