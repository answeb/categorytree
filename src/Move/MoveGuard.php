<?php

declare(strict_types=1);

namespace Answeb\CategoryTree\Move;

use Answeb\CategoryTree\Exception\CannotMoveCategoryException;

class MoveGuard
{
    public function __construct(
        private readonly CategoryPositionRepository $positionRepository,
    ) {
    }

    /**
     * @throws CannotMoveCategoryException
     */
    public function check(MoveRequest $request, int $shopId): void
    {
        $category = $this->positionRepository->findCategory($request->categoryId);
        if (null === $category) {
            throw new CannotMoveCategoryException('Cette catégorie n\'existe pas.');
        }

        $homeId = $this->positionRepository->getHomeCategoryId($shopId);
        if ($category['parentId'] === 0 || $category['id'] === $homeId) {
            throw new CannotMoveCategoryException('Cette catégorie ne peut pas être déplacée.');
        }

        $parent = $this->positionRepository->findCategory($request->newParentId);
        if (null === $parent) {
            throw new CannotMoveCategoryException('La catégorie de destination n\'existe pas.');
        }

        if ($parent['nleft'] >= $category['nleft'] && $parent['nleft'] <= $category['nright']) {
            throw new CannotMoveCategoryException('Une catégorie ne peut pas être déplacée dans elle-même ou dans l\'une de ses sous-catégories.');
        }

        $home = $this->positionRepository->findCategory($homeId);
        if (null === $home || $parent['nleft'] < $home['nleft'] || $parent['nleft'] > $home['nright']) {
            throw new CannotMoveCategoryException('La catégorie de destination n\'est pas dans l\'arborescence de la boutique.');
        }

        $siblingIds = array_diff(
            $this->positionRepository->getChildrenIds($request->newParentId, $shopId),
            [$request->categoryId]
        );
        if ($request->newIndex > count($siblingIds)) {
            throw new CannotMoveCategoryException('La position demandée n\'est pas valide.');
        }
    }
}
