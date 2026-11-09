<?php

declare(strict_types=1);

namespace Answeb\CategoryTree\Move;

use Answeb\CategoryTree\Exception\CannotMoveCategoryException;
use Answeb\CategoryTree\Exception\StaleTreeException;
use Category;
use Hook;
use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;
use PrestaShop\PrestaShop\Core\Domain\Category\Command\EditCategoryCommand;

class CategoryMover
{
    public function __construct(
        private readonly MoveGuard $guard,
        private readonly CategoryPositionRepository $positionRepository,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    /**
     * @return MoveRequest|null Payload inverse, ou null si rien n'a changé
     *
     * @throws CannotMoveCategoryException
     * @throws StaleTreeException
     */
    public function move(MoveRequest $request, int $shopId): ?MoveRequest
    {
        $this->guard->check($request, $shopId);

        $category = $this->positionRepository->findCategory($request->categoryId);
        $oldParentId = $category['parentId'];
        $oldParentChildren = $this->positionRepository->getChildrenIds($oldParentId, $shopId);
        $oldIndex = array_search($request->categoryId, $oldParentChildren, true);

        if ($oldParentId !== $request->expectedParentId || $oldIndex !== $request->expectedIndex) {
            throw new StaleTreeException();
        }

        $parentChanged = $oldParentId !== $request->newParentId;
        if (!$parentChanged && $oldIndex === $request->newIndex) {
            return null;
        }

        $oldSiblings = array_values(array_diff($oldParentChildren, [$request->categoryId]));
        $newSiblings = $parentChanged
            ? array_values(array_diff($this->positionRepository->getChildrenIds($request->newParentId, $shopId), [$request->categoryId]))
            : $oldSiblings;
        array_splice($newSiblings, $request->newIndex, 0, [$request->categoryId]);

        $this->positionRepository->beginTransaction();
        try {
            if ($parentChanged) {
                $command = new EditCategoryCommand($request->categoryId);
                $command->setParentCategoryId($request->newParentId);
                $this->commandBus->handle($command);
            }

            $this->positionRepository->writePositions($newSiblings, $shopId);
            if ($parentChanged) {
                $this->positionRepository->writePositions($oldSiblings, $shopId);
            }

            $this->positionRepository->commit();
        } catch (\Throwable $e) {
            $this->positionRepository->rollBack();

            throw $e;
        }

        if (!$parentChanged) {
            Hook::exec('actionCategoryUpdate', ['category' => new Category($request->categoryId)]);
        }

        return new MoveRequest(
            $request->categoryId,
            $oldParentId,
            $oldIndex,
            $request->newParentId,
            $request->newIndex
        );
    }
}
