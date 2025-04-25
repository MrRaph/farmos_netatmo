<?php

namespace Drupal\farm_netatmo\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\farm_netatmo\NetatmoService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gère l’autorisation OAuth avec Netatmo.
 */
class AuthorizationController extends ControllerBase {

  public function __construct(
    protected NetatmoService $netatmo,
    protected AccountInterface $currentUser,
    protected \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public static function create(ContainerInterface $c): static {
    return new static(
      $c->get('farm_netatmo.netatmo_service'),
      $c->get('current_user'),
      $c->get('logger.factory'),
    );
  }

  /**
   * Étape 1 : redirige vers le portail Netatmo pour autoriser l’app.
   */
  public function authorize(): RedirectResponse {
    $state = bin2hex(random_bytes(8));
    $this->netatmo->storeState($state, $this->currentUser->id());

    $url = $this->netatmo->buildAuthorizeUrl($state);
    return new RedirectResponse($url);
  }

  /**
   * Étape 2 : Netatmo redirige ici avec ?code=…&state=…
   */
  public function callback(Request $request): RedirectResponse {
    $code  = $request->query->get('code');
    $state = $request->query->get('state');

    if (!$this->netatmo->isStateValid($state)) {
      $this->messenger()->addError($this->t('Invalid OAuth state.'));
      return $this->redirect('farm_netatmo.settings');
    }

    try {
      $this->netatmo->exchangeAuthorizationCode($code);
      $this->messenger()->addStatus($this->t('Netatmo authorization successful.'));
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('farm_netatmo')->error($e->getMessage());
      $this->messenger()->addError($this->t('Failed to authorize Netatmo: @m', ['@m' => $e->getMessage()]));
    }

    return $this->redirect('farm_netatmo.settings');
  }

}
