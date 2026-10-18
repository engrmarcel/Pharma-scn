<?php

namespace App\Livewire\Catalogue;

use App\Domain\Catalogue\Enums\ProductStatus;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Platform\Models\DosageForm;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Product catalogue')]
class ProductBrowser extends Component
{
    use WithPagination;

    #[Url]
    public string $q = '';

    #[Url]
    public string $form = '';

    public ?string $selected = null;

    public function updating(string $property): void
    {
        if (in_array($property, ['q', 'form'], true)) {
            $this->resetPage();
        }
    }

    public function select(string $id): void
    {
        $this->selected = $id;
    }

    public function render()
    {
        $staff = Gate::any(['catalogue.manage', 'catalogue.review']);
        $visible = fn () => Product::query()->when(! $staff, fn ($q) => $q->where('status', '!=', ProductStatus::UnderReview->value));

        return view('livewire.catalogue.product-browser', [
            'products' => $visible()
                ->search(mb_substr($this->q, 0, 100))
                ->when($this->form !== '', fn ($q) => $q->where('dosage_form_code', $this->form))
                ->with(['brand', 'manufacturer'])
                ->orderBy('display_name')
                ->paginate(20),
            'forms' => DosageForm::query()->orderBy('name')->get(),
            'detail' => $this->selected
                ? $visible()->with(['ingredients', 'packageLevels', 'identifiers', 'classifications', 'regulatoryIdentifiers.authority'])->find($this->selected)
                : null,
        ]);
    }
}
