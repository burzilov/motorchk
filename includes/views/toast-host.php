<div
    class="mc-toast-stack"
    x-data
    x-cloak
    aria-live="polite"
    aria-relevant="additions"
>
    <template x-for="item in $store.toast.items" :key="item.id">
        <div class="mc-toast" :class="'mc-toast--' + item.type" role="status">
            <span class="mc-toast__message" x-text="item.message"></span>
            <button
                type="button"
                class="mc-toast__close"
                @click="$store.toast.dismiss(item.id)"
                aria-label="Закрыть"
            >&times;</button>
        </div>
    </template>
</div>
