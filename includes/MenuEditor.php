<?php

class MenuEditor
{
    public function __construct(
        private PageTree $pageTree,
        private MenuWriter $menuWriter,
    ) {
    }

    public function listPagesForPicker(): array
    {
        $pages = [];
        foreach ($this->pageTree->getFlatNodesForSelect() as $node) {
            $pages[] = [
                'slug' => $node['slug'],
                'title' => $node['title'],
                'published' => !empty($node['published']),
            ];
        }

        return $pages;
    }

    public function saveItems(string $locationId, array $items): void
    {
        $this->menuWriter->saveLocationItems($locationId, $items);
    }

    public function createLocation(string $id, string $label): void
    {
        $this->menuWriter->createLocation($id, $label);
    }

    public function updateLocation(string $id, string $label, ?string $newId = null): void
    {
        $this->menuWriter->updateLocation($id, $label, $newId);
    }

    public function deleteLocation(string $id): void
    {
        $this->menuWriter->deleteLocation($id);
    }

    public function viewData(string $locationId): array
    {
        $locations = $this->menuWriter->listLocations();
        $ids = array_column($locations, 'id');
        if ($locationId === '' || !in_array($locationId, $ids, true)) {
            $locationId = MenuWriter::DEFAULT_LOCATION;
        }

        $menus = $this->menuWriter->loadMenus();
        $current = $menus[$locationId] ?? ['label' => $locationId, 'items' => []];

        return [
            'locations' => $locations,
            'locationId' => $locationId,
            'locationLabel' => $current['label'],
            'items' => $current['items'],
            'pages' => $this->listPagesForPicker(),
            'defaultLocation' => MenuWriter::DEFAULT_LOCATION,
        ];
    }
}
