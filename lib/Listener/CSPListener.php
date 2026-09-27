<?php

declare(strict_types=1);

namespace OCA\Arcade\Listener;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\CoreMap;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Share\IManager as IShareManager;

/**
 * Nostalgist.js compiles the RetroArch cores to WebAssembly and runs them
 * from blob: URLs, which the default policy blocks. The player also runs
 * inside the Files app (through the Viewer), so the allowances are added
 * on every page a user with the app could see -- a path check would miss
 * the Viewer.
 *
 * Only the needed directives are added, through an empty policy: adding a
 * full ContentSecurityPolicy here would merge all of its defaults into the
 * policy of every page of the instance.
 *
 * @template-implements IEventListener<AddContentSecurityPolicyEvent>
 */
class CSPListener implements IEventListener {
	/**
	 * How many children of a shared folder are looked at before giving
	 * up: enough to find a game in any real library folder, small enough
	 * to keep an anonymous page render cheap.
	 */
	private const FOLDER_SCAN_LIMIT = 200;

	public function __construct(
		private IUserSession $userSession,
		private IAppManager $appManager,
		private IRequest $request,
		private IShareManager $shareManager,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof AddContentSecurityPolicyEvent) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			// A game shared by link plays for anonymous visitors too -- the
			// Viewer fires on share pages and the player takes the share's
			// own URL as its source -- so those pages keep the allowances.
			// Only pages whose share holds a game, though: every other
			// share, and everything else without a user, the login page
			// above all, keeps the default policy.
			$token = $this->publicShareToken();
			if ($token === null || !$this->shareHoldsGames($token)) {
				return;
			}
		} elseif (!$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
			// "Enable app for specific groups" is a promise the policy keeps too.
			return;
		}
		$csp = new EmptyContentSecurityPolicy();
		$csp->allowEvalWasm(true);
		$csp->addAllowedScriptDomain('blob:');
		// Without worker-src the cores fall through to default-src, which
		// blocks them. Nextcloud 34 dropped child-src, the fallback older
		// browsers used, so worker-src is all there is to set.
		$csp->addAllowedWorkerSrcDomain('blob:');
		$csp->addAllowedFrameDomain('blob:');
		// The emulator fetches its core, ROM and save data as blobs.
		$csp->addAllowedConnectDomain('blob:');
		$csp->addAllowedConnectDomain('data:');
		$csp->addAllowedImageDomain('blob:');
		$csp->addAllowedImageDomain('data:');
		$csp->addAllowedMediaDomain('blob:');
		$csp->addAllowedMediaDomain('data:');
		$event->addPolicy($csp);
	}

	/**
	 * The token of the public share link this request renders, where the
	 * Viewer -- and with it the player -- can open without anybody logged
	 * in. Null when the request is no share page at all.
	 */
	private function publicShareToken(): ?string {
		try {
			$path = $this->request->getPathInfo();
		} catch (\Throwable) {
			// getPathInfo() throws on requests it cannot make sense of;
			// such a request renders no share page.
			return null;
		}
		if (!is_string($path) || !str_starts_with($path, '/s/')) {
			return null;
		}
		$token = explode('/', $path)[2] ?? '';
		return $token === '' ? null : $token;
	}

	/**
	 * Whether the share behind a token holds anything the emulator would
	 * play, so every other share page keeps the default policy.
	 *
	 * A password-protected share resolves here before the visitor has
	 * typed the password, so a ROM share's password page carries the
	 * allowances too. That is deliberate: the policy only matters once
	 * the Viewer can render, and granting wasm/blob on the password page
	 * of a game reveals and risks nothing.
	 */
	private function shareHoldsGames(string $token): bool {
		try {
			$node = $this->shareManager->getShareByToken($token)->getNode();
		} catch (\Throwable) {
			// An unknown or expired token shares nothing.
			return false;
		}
		$romMimes = array_fill_keys(array_values(CoreMap::extensionMimeMap()), true);
		if ($node instanceof File) {
			return isset($romMimes[$node->getMimetype()]);
		}
		if (!$node instanceof Folder) {
			return false;
		}
		// A shared folder plausibly holds games when a ROM sits among its
		// direct children -- by mimetype, or by extension for files
		// uploaded before the app registered its mimetypes. Only one
		// listing, and only the first entries of it: a game buried deeper,
		// or beyond the limit, keeps the default policy and simply does
		// not play anonymously -- a fair price for not walking a stranger's
		// share tree on every page load.
		$romExtensions = CoreMap::extensionMimeMap();
		try {
			$children = $node->getDirectoryListing();
		} catch (\Throwable) {
			return false;
		}
		foreach (array_slice($children, 0, self::FOLDER_SCAN_LIMIT) as $child) {
			if (!$child instanceof File) {
				continue;
			}
			if (isset($romMimes[$child->getMimetype()])) {
				return true;
			}
			$extension = strtolower(pathinfo($child->getName(), PATHINFO_EXTENSION));
			if (isset($romExtensions[$extension])) {
				return true;
			}
		}
		return false;
	}
}
