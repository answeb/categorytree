<?php

declare(strict_types=1);

namespace Answeb\CategoryTree\Controller\Admin;

use Answeb\CategoryTree\Exception\CannotMoveCategoryException;
use Answeb\CategoryTree\Exception\StaleTreeException;
use Answeb\CategoryTree\Move\CategoryMover;
use Answeb\CategoryTree\Move\MoveRequest;
use Answeb\CategoryTree\Tree\CategoryTreeProvider;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use PrestaShopLogger;
use Shop;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CategoryTreeController extends PrestaShopAdminController
{
    private const DOMAIN = 'Modules.Categorytree.Admin';

    /** Identifiant fictif remplacé côté JS dans les modèles d'URL. */
    private const URL_PLACEHOLDER_ID = 987654321;

    public function __construct(
        private readonly CategoryTreeProvider $treeProvider,
        private readonly CategoryMover $mover,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function indexAction(): Response
    {
        return $this->render('@Modules/categorytree/views/templates/admin/tree.html.twig', [
            'layoutTitle' => $this->trans('Arborescence des catégories', [], self::DOMAIN),
            'multistoreActive' => Shop::isFeatureActive(),
            'canMove' => $this->isGranted('update', 'AdminCategories'),
            'placeholderId' => self::URL_PLACEHOLDER_ID,
            'dataUrl' => $this->generateUrl('category_tree_data'),
            'moveUrl' => $this->generateUrl('category_tree_move'),
            'editUrlTemplate' => $this->generateUrl('admin_categories_edit', ['categoryId' => self::URL_PLACEHOLDER_ID]),
            'createUrlTemplate' => $this->generateUrl('admin_categories_create', ['id_parent' => self::URL_PLACEHOLDER_ID]),
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", jsonResponse: true)]
    public function dataAction(): JsonResponse
    {
        if (Shop::isFeatureActive()) {
            return $this->multistoreResponse();
        }

        return new JsonResponse(['tree' => $this->getTree()]);
    }

    #[AdminSecurity("is_granted('update', 'AdminCategories')", jsonResponse: true)]
    public function moveAction(Request $request): JsonResponse
    {
        if (Shop::isFeatureActive()) {
            return $this->multistoreResponse();
        }

        try {
            $moveRequest = MoveRequest::fromArray(json_decode($request->getContent(), true, 4, JSON_THROW_ON_ERROR));
        } catch (\JsonException|\InvalidArgumentException) {
            return new JsonResponse(
                ['error' => $this->trans('Requête invalide.', [], self::DOMAIN)],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $undo = $this->mover->move($moveRequest, $this->getShopContext()->getId());
        } catch (CannotMoveCategoryException $e) {
            return new JsonResponse(
                ['error' => $this->trans($e->getMessage(), $e->getParameters(), self::DOMAIN)],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (StaleTreeException) {
            return new JsonResponse([
                'error' => $this->trans(
                    'L\'arborescence a été modifiée entre-temps. Elle vient d\'être rechargée, veuillez recommencer.',
                    [],
                    self::DOMAIN
                ),
                'tree' => $this->getTree(),
            ], Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('categorytree: ' . $e->getMessage(), 3, null, 'Category', $moveRequest->categoryId);

            return new JsonResponse([
                'error' => $this->getErrorMessageForException($e),
                'tree' => $this->getTree(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'tree' => $this->getTree(),
            'undo' => $undo?->toArray(),
        ]);
    }

    private function getTree(): array
    {
        return $this->treeProvider->getTree(
            $this->getShopContext()->getId(),
            $this->getLanguageContext()->getId()
        );
    }

    private function multistoreResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->trans('Module non compatible avec le multiboutique', [], self::DOMAIN)],
            Response::HTTP_BAD_REQUEST
        );
    }
}
