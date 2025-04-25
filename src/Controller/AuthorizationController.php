<?php

namespace Drupal\farm_netatmo\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\farm_netatmo\NetatmoService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gère l’autorisation OAuth Netatmo.
 */
class AuthorizationController extends ControllerBase {

  public function __construct(
    protected NetatmoService $netatmo,
  ) {}

  public static function create(ContainerInterface $c): static {
    return new static($c->get('farm_netatmo.netatmo_service'));
  }

  /**
   * Étape 1 : redirige vers le portail Netatmo pour autorisation.
   */
  public function authorize(): RedirectResponse {
    $state = bin2hex(random_bytes(8));
    $this->netatmo->storeState($state, $this->currentUser()->id());

    return new RedirectResponse($this->netatmo->buildAuthorizeUrl($state));
  }

  /**
   * Étape 2 : callback OAuth (/farm/netatmo/oauth/callback).
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
      $this->logger('farm_netatmo')->error($e->getMessage());
      $this->messenger()->addError($this->t('Failed to authorize Netatmo: @m', ['@m' => $e->getMessage()]));
    }

    return $this->redirect('farm_netatmo.settings');
  }

}