@props(['url' => null, 'name' => '', 'initials' => ''])

{{--
    Foto de perfil: escolher, enquadrar (arrastar e dar zoom) e salvar.
    O recorte quadrado é feito no navegador (canvas) e enviado ao Livewire como
    data URL na propriedade "croppedPhoto"; o servidor valida e recodifica.
    Usa $wire da página que contém o componente (savePhoto / removePhoto).
--}}
<div
    class="my-6 flex flex-col gap-6 rounded-xl border border-zinc-800 bg-zinc-900/40 p-5 sm:flex-row sm:items-center"
    x-data="{
        size: 256,
        img: null,
        zoom: 1,
        ox: 0,
        oy: 0,
        drag: null,
        error: '',
        saving: false,
        pick(event) {
            const file = event.target.files[0];
            event.target.value = '';
            if (! file) return;
            this.error = '';
            if (! ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                this.error = @js(__('Choose a JPG, PNG or WebP image.'));
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                this.error = @js(__('The image must be at most 5 MB.'));
                return;
            }
            const image = new Image();
            image.onload = () => {
                this.img = image;
                this.zoom = 1;
                this.ox = 0;
                this.oy = 0;
                this.$nextTick(() => this.draw());
            };
            image.onerror = () => { this.error = @js(__('Could not read this image.')); };
            image.src = URL.createObjectURL(file);
        },
        scale() {
            return Math.max(this.size / this.img.width, this.size / this.img.height) * this.zoom;
        },
        draw() {
            if (! this.img) return;
            const scale = this.scale();
            const width = this.img.width * scale;
            const height = this.img.height * scale;
            const maxX = Math.max(0, (width - this.size) / 2);
            const maxY = Math.max(0, (height - this.size) / 2);
            this.ox = Math.min(maxX, Math.max(-maxX, this.ox));
            this.oy = Math.min(maxY, Math.max(-maxY, this.oy));
            const context = this.$refs.canvas.getContext('2d');
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, this.size, this.size);
            context.drawImage(this.img, (this.size - width) / 2 + this.ox, (this.size - height) / 2 + this.oy, width, height);
        },
        down(event) {
            this.drag = { x: event.clientX, y: event.clientY, ox: this.ox, oy: this.oy };
            event.target.setPointerCapture(event.pointerId);
        },
        move(event) {
            if (! this.drag) return;
            const ratio = this.size / this.$refs.canvas.getBoundingClientRect().width;
            this.ox = this.drag.ox + (event.clientX - this.drag.x) * ratio;
            this.oy = this.drag.oy + (event.clientY - this.drag.y) * ratio;
            this.draw();
        },
        up() {
            this.drag = null;
        },
        cancel() {
            this.img = null;
            this.error = '';
        },
        async save() {
            this.draw();
            this.saving = true;
            await this.$wire.set('croppedPhoto', this.$refs.canvas.toDataURL('image/jpeg', 0.9));
            await this.$wire.savePhoto();
            this.saving = false;
        },
    }"
>
    {{-- Foto atual (ou a padrão com as iniciais) --}}
    <div x-show="! img" class="flex items-center gap-5">
        <flux:avatar size="xl" circle :name="$name" :initials="$initials" :src="$url" color="auto" class="!size-24 !text-3xl" />

        <div>
            <flux:heading>{{ __('Profile photo') }}</flux:heading>
            <flux:text class="mt-1 text-sm">{{ __('JPG, PNG or WebP, up to 5 MB.') }}</flux:text>

            <div class="mt-3 flex flex-wrap gap-2">
                <label>
                    <input type="file" class="sr-only" accept="image/jpeg,image/png,image/webp" x-on:change="pick($event)" data-test="photo-input" />
                    <span class="inline-flex h-8 cursor-pointer items-center rounded-lg bg-accent px-3 text-sm font-medium text-black">
                        {{ $url ? __('Change photo') : __('Choose photo') }}
                    </span>
                </label>

                @if ($url)
                    <flux:button size="sm" variant="ghost" wire:click="removePhoto" data-test="remove-photo-button">
                        {{ __('Remove photo') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </div>

    {{-- Enquadramento --}}
    <div x-show="img" x-cloak class="flex flex-col items-center gap-4 sm:flex-row sm:items-center sm:gap-6">
        <canvas
            x-ref="canvas"
            width="256"
            height="256"
            class="size-48 shrink-0 cursor-grab touch-none rounded-full border border-zinc-700 active:cursor-grabbing"
            x-on:pointerdown="down($event)"
            x-on:pointermove="move($event)"
            x-on:pointerup="up()"
            x-on:pointercancel="up()"
        ></canvas>

        <div class="w-full max-w-xs">
            <flux:text class="text-sm">{{ __('Drag the photo to adjust the framing.') }}</flux:text>

            <label class="mt-3 block text-sm text-zinc-400">
                {{ __('Zoom') }}
                <input type="range" min="1" max="3" step="0.01" class="mt-1 w-full accent-amber-500" x-model.number="zoom" x-on:input="draw()" />
            </label>

            <div class="mt-4 flex gap-2">
                <flux:button size="sm" variant="primary" x-on:click="save()" x-bind:disabled="saving" data-test="save-photo-button">
                    {{ __('Save photo') }}
                </flux:button>
                <flux:button size="sm" variant="ghost" x-on:click="cancel()">{{ __('Cancel') }}</flux:button>
            </div>
        </div>
    </div>

    <p x-show="error" x-cloak x-text="error" class="text-sm text-red-400"></p>
    @error('croppedPhoto')
        <p class="text-sm text-red-400">{{ $message }}</p>
    @enderror
</div>
