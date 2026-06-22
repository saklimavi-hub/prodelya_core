@php
    $isEdit = $template->exists;
    $formAction = $isEdit ? route('admin.notifications.templates.update', $template) : route('admin.notifications.templates.store');
    $previewSample = old('sample_context', $form['sample_context'] ?? '');
    $selectedAudienceLabel = $audienceOptions[old('audience_type', $form['audience_type'])] ?? 'İç Ekip';
@endphp

<style>
    .template-form-shell {
        font-family: Arial, Helvetica, sans-serif;
    }
    .template-form-shell .pd-soft-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .template-form-shell .pd-code-box {
        font-family: Consolas, Monaco, monospace;
        font-size: 12px;
    }
    .template-form-shell .pd-help-item + .pd-help-item {
        margin-top: 10px;
    }
</style>

<div class="template-form-shell">
    @if($errors->any())
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            Lütfen formdaki alanları kontrol edin.
        </div>
    @endif

    <div class="mb-6 rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
        Hazır bir varsayılan şablonu düzenleyebilir veya kendi tenant dilinize göre yeni bir şablon kaydedebilirsiniz. Güvenli olmayan alanlar önizleme ve değişken yardımında gösterilmez.
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2 space-y-6">
            <div class="pd-soft-card">
                <div class="px-4 py-5 sm:p-6">
                    <form id="notification-template-form" method="POST" action="{{ $formAction }}" class="space-y-5">
                        @csrf
                        @if($isEdit)
                            @method('PUT')
                        @endif

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Olay</label>
                                <select name="notification_key" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                                    @foreach($eventOptions as $event)
                                        <option value="{{ $event['key'] }}" @selected(old('notification_key', $form['notification_key']) === $event['key'])>{{ $event['label'] }}</option>
                                    @endforeach
                                </select>
                                @error('notification_key')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Kanal</label>
                                <select name="channel" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                                    @foreach($channelOptions as $key => $channel)
                                        <option value="{{ $key }}" @selected(old('channel', $form['channel']) === $key) @disabled(($channel['status'] ?? 'passive') !== 'active')>
                                            {{ $channel['label'] }}{{ ($channel['status'] ?? 'passive') !== 'active' ? ' (Pasif)' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('channel')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Kime Gönderilecek</label>
                                <select name="audience_type" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                                    @foreach($audienceOptions as $key => $label)
                                        <option value="{{ $key }}" @selected(old('audience_type', $form['audience_type']) === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('audience_type')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Başlık</label>
                                <input name="title" type="text" value="{{ old('title', $form['title']) }}" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                                @error('title')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Başlık Satırı</label>
                            <input name="subject" type="text" value="{{ old('subject', $form['subject']) }}" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                            <p class="mt-2 text-xs text-gray-500">E-posta ve önizleme başlığında görünür. İç bildirimlerde kısa özet gibi kullanabilirsiniz.</p>
                            @error('subject')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Mesaj İçeriği</label>
                            <textarea name="body" rows="11" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-3 text-sm text-gray-900">{{ old('body', $form['body']) }}</textarea>
                            <p class="mt-2 text-xs text-gray-500">Değişken eklemek için sağdaki yardım kutusundaki alanları kullanabilirsiniz.</p>
                            @error('body')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center gap-3">
                            <input id="is_active" name="is_active" type="checkbox" value="1" class="h-4 w-4 rounded border-gray-300 text-blue-600" @checked(old('is_active', $form['is_active']))>
                            <label for="is_active" class="text-sm font-medium text-gray-700">Aktif</label>
                        </div>

                        <div class="flex items-center gap-3">
                            <button type="submit" class="pd-btn pd-btn-primary">{{ $isEdit ? 'Kaydet ve Güncelle' : 'Şablonu Kaydet' }}</button>
                            <a href="{{ route('admin.notifications.templates.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">Listeye dön</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="pd-soft-card">
                <div class="px-4 py-5 sm:p-6">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Şablon Önizleme</h3>
                            <p class="mt-1 text-sm text-gray-500">Örnek veriyle önizleyin. Güvenli olmayan alanlar otomatik temizlenir.</p>
                        </div>
                        <span class="badge badge-gray">{{ $selectedAudienceLabel }}</span>
                    </div>

                    <form id="notification-template-preview-form" method="POST" action="{{ route('admin.notifications.templates.preview') }}" class="mt-5 space-y-5">
                        @csrf
                        @if($isEdit)
                            <input type="hidden" name="template_id" value="{{ $template->id }}">
                        @endif

                        <input type="hidden" name="notification_key" value="{{ old('notification_key', $form['notification_key']) }}">
                        <input type="hidden" name="channel" value="{{ old('channel', $form['channel']) }}">
                        <input type="hidden" name="audience_type" value="{{ old('audience_type', $form['audience_type']) }}">
                        <input type="hidden" name="title" value="{{ old('title', $form['title']) }}">
                        <input type="hidden" name="subject" value="{{ old('subject', $form['subject']) }}">
                        <textarea name="body" class="hidden">{{ old('body', $form['body']) }}</textarea>
                        <input type="hidden" name="is_active" value="{{ old('is_active', $form['is_active']) ? 1 : 0 }}">

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Örnek Veri</label>
                            <textarea name="sample_context" rows="8" class="pd-code-box mt-1 block w-full rounded-md border border-gray-300 bg-slate-950 px-3 py-3 text-slate-100" placeholder='{"customer_name":"ABC İnşaat","quote_number":"TK-2026-0042"}'>{{ $previewSample }}</textarea>
                            @error('sample_context')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                            <p class="mt-2 text-xs text-gray-500">Güvenli olmayan alanlar önizlemeye alınmaz.</p>
                        </div>

                        <button type="submit" class="pd-btn pd-btn-light">Örnek Veriyle Önizle</button>
                    </form>

                    @if($preview)
                        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <div class="rounded-lg border border-gray-200 bg-white px-4 py-4">
                                <div class="text-sm font-medium text-gray-900">Başlık</div>
                                <div class="mt-2 text-sm text-gray-700">{{ $preview['subject'] ?: '—' }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-white px-4 py-4">
                                <div class="text-sm font-medium text-gray-900">Önizleme Durumu</div>
                                <div class="mt-2 text-sm text-gray-700">
                                    @if(count($preview['blocked_variables']) + count($preview['removed_context_keys']) + count($preview['missing_variables']) === 0)
                                        Önizleme temiz şekilde üretildi.
                                    @else
                                        {{ count($preview['blocked_variables']) + count($preview['removed_context_keys']) + count($preview['missing_variables']) }} alan için güvenli sadeleştirme uygulandı.
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 rounded-lg border border-gray-200 bg-white px-4 py-4">
                            <div class="text-sm font-medium text-gray-900">Mesaj</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm text-gray-700">{{ $preview['body'] ?: '—' }}</div>
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                                <div class="text-sm font-medium text-gray-900">Kullanılabilir Değişkenler</div>
                                <div class="mt-2 text-xs text-gray-600">{{ count($preview['allowed_variables']) > 0 ? implode(', ', $preview['allowed_variables']) : 'Yok' }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                                <div class="text-sm font-medium text-gray-900">Temizlenen Alanlar</div>
                                <div class="mt-2 text-xs text-gray-600">
                                    {{ count($preview['blocked_variables']) > 0 ? implode(', ', $preview['blocked_variables']) : 'Yok' }}
                                </div>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                                <div class="text-sm font-medium text-gray-900">Eksik Değerler</div>
                                <div class="mt-2 text-xs text-gray-600">
                                    {{ count($preview['missing_variables']) > 0 ? implode(', ', $preview['missing_variables']) : 'Yok' }}
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="pd-soft-card">
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-lg font-semibold text-gray-900">Kullanılabilir Değişkenler</h3>
                    <p class="mt-1 text-sm text-gray-500">Seçilen hedef kitle için güvenli olarak kullanılabilecek alanlar listelenir.</p>

                    <div class="mt-4 space-y-4">
                        @foreach($variableHelp as $group)
                            <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                                <div class="text-sm font-semibold text-gray-900">{{ $group['label'] }}</div>
                                <div class="mt-3 space-y-2">
                                    @foreach($group['items'] as $item)
                                        <div class="pd-help-item">
                                            <div class="pd-code-box text-[12px] font-semibold text-slate-900">{{ $item['placeholder'] }}</div>
                                            <div class="mt-1 text-xs text-gray-600">{{ $item['description'] }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            @if($selectedEvent)
                <div class="pd-soft-card">
                    <div class="px-4 py-5 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900">Seçili Olay</h3>
                        <div class="mt-4 space-y-3 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-gray-500">Olay</span>
                                <span class="font-medium text-gray-900">{{ $selectedEvent['label'] }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-gray-500">Grup</span>
                                <span class="font-medium text-gray-900">{{ ucfirst($selectedEvent['category']) }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-gray-500">Varsayılan hedef kitle</span>
                                <span class="font-medium text-gray-900">{{ $audienceOptions[$selectedEvent['default_audience']] ?? $selectedEvent['default_audience'] }}</span>
                            </div>
                            <div class="text-gray-600">{{ $selectedEvent['description'] }}</div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
    (function () {
        const mainForm = document.getElementById('notification-template-form');
        const previewForm = document.getElementById('notification-template-preview-form');

        if (!mainForm || !previewForm) {
            return;
        }

        previewForm.addEventListener('submit', function () {
            const fieldNames = ['notification_key', 'channel', 'audience_type', 'title', 'subject', 'body'];

            fieldNames.forEach(function (fieldName) {
                const source = mainForm.querySelector('[name="' + fieldName + '"]');
                const target = previewForm.querySelector('[name="' + fieldName + '"]');

                if (!source || !target) {
                    return;
                }

                target.value = source.value;
            });

            const activeSource = mainForm.querySelector('[name="is_active"]');
            const activeTarget = previewForm.querySelector('[name="is_active"]');

            if (activeSource && activeTarget) {
                activeTarget.value = activeSource.checked ? '1' : '0';
            }
        });
    }());
</script>
@endpush
