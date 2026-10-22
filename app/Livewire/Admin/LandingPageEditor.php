<?php

namespace App\Livewire\Admin;

use App\Domain\Platform\Services\LandingPageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Administration panel editor for the public landing page: every text, link, statistic,
 * network node, service, step, feature, app bullet and screenshot, and the hero background
 * (flowing particles or a video with an overlay). Saving validates and audits the change;
 * uploads are stored by the platform and served at /landing-media/{file}.
 */
#[Layout('layouts.app')]
#[Title('Landing page')]
class LandingPageEditor extends Component
{
    use WithFileUploads;

    public array $content = [];

    /** @var TemporaryUploadedFile|null */
    public $video = null;

    /** @var TemporaryUploadedFile|null */
    public $poster = null;

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $shots = [];

    public function mount(LandingPageService $landing): void
    {
        $this->authorizeEdit();
        $this->content = $landing->content();
    }

    public function add(string $list): void
    {
        $this->authorizeEdit();
        $blank = match ($list) {
            'stats' => ['value' => 0, 'suffix' => '', 'label' => ''],
            'network.nodes', 'network.services' => ['label' => '', 'description' => '', 'icon' => 'cube'],
            'journey.steps' => ['title' => '', 'description' => ''],
            'features.items' => ['title' => '', 'description' => '', 'icon' => 'sparkles'],
            'app.bullets' => '',
            'app.screenshots' => ['url' => '/images/landing/app-my-day.webp', 'caption' => ''],
            default => throw new \InvalidArgumentException($list),
        };
        $items = data_get($this->content, $list, []);
        $items[] = $blank;
        data_set($this->content, $list, $items);
    }

    public function remove(string $list, int $index): void
    {
        $this->authorizeEdit();
        $items = data_get($this->content, $list, []);
        unset($items[$index]);
        data_set($this->content, $list, array_values($items));
        if ($list === 'app.screenshots') {
            $this->shots = [];
        }
    }

    public function move(string $list, int $index, int $direction): void
    {
        $this->authorizeEdit();
        $items = data_get($this->content, $list, []);
        $target = $index + $direction;
        if (isset($items[$index], $items[$target])) {
            [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
            data_set($this->content, $list, $items);
        }
    }

    public function save(LandingPageService $landing): void
    {
        $this->authorizeEdit();
        $this->validate([
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm', 'max:10240'],
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'shots.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        if ($this->video) {
            $this->content['background']['video_url'] = $this->store($this->video);
            $this->content['background']['mode'] = 'video';
        }
        if ($this->poster) {
            $this->content['background']['poster_url'] = $this->store($this->poster);
        }
        foreach ($this->shots as $i => $file) {
            if ($file && isset($this->content['app']['screenshots'][$i])) {
                $this->content['app']['screenshots'][$i]['url'] = $this->store($file);
            }
        }

        try {
            $this->content = $landing->save($this->content, auth()->user());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["content.{$k}" => $m])->all());
        }
        $this->reset('video', 'poster', 'shots');
        session()->flash('status', 'Landing page published.');
    }

    public function resetToDefaults(LandingPageService $landing): void
    {
        $this->authorizeEdit();
        $this->content = $landing->reset(auth()->user());
        $this->reset('video', 'poster', 'shots');
        session()->flash('status', 'Landing page reset to the default content.');
    }

    public function render()
    {
        return view('livewire.admin.landing-page-editor', ['icons' => LandingPageService::ICONS]);
    }

    private function store(UploadedFile $file): string
    {
        $name = Str::lower(Str::random(32)).'.'.$file->guessExtension();
        $file->storeAs('landing', $name, 'local');

        return '/landing-media/'.$name;
    }

    private function authorizeEdit(): void
    {
        Gate::authorize('platform.settings.manage');
    }
}
