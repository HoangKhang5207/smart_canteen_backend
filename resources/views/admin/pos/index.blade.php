@extends('admin.layout')

@section('title', 'POS Quầy Căn tin — Smart Canteen Admin')

@section('content')
<div class="flex-between" style="margin-bottom: 1.25rem;">
    <div>
        <h1 style="font-size: 1.35rem; margin: 0;">POS Quầy Căn tin (Thu ngân)</h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.2rem 0 0;">
            Quét/nhập mã thẻ học sinh để kiểm tra hạn mức tức thì, chọn nhiều món trừ tồn kho nguyên tử và in biên lai POS.
        </p>
    </div>
    <span class="badge badge-ACTIVE">Thu ngân: {{ auth()->user()->name }}</span>
</div>

<div style="display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(300px, 1fr); gap: 1.25rem; align-items: start;">
    <div>
        <div class="card">
            <h2 style="font-size: 1rem; margin: 0 0 0.75rem;">1. Quét / Nhập Mã Thẻ Học Sinh</h2>
            <div style="display: flex; gap: 0.5rem;">
                <input id="pos-card-input" type="text" placeholder="Nhập mã thẻ (VD: F156BA) hoặc mã HS (HS-2026-0001)..." autocomplete="off">
                <button id="pos-lookup-btn" type="button" class="btn btn-primary" style="white-space: nowrap;">Tra cứu thẻ</button>
                <button id="pos-clear-card-btn" type="button" class="btn btn-secondary" style="white-space: nowrap;">Xóa thẻ</button>
            </div>
            <div id="pos-card-error" role="alert" style="display:none; margin-top:0.6rem; padding:0.65rem 0.8rem; border-radius:8px; background:#FEF2F2; color:#991B1B; border:1px solid #FECACA; font-size:0.84rem; font-weight:600;"></div>
            <div id="pos-card-info" style="display:none; margin-top:0.75rem; padding:0.85rem; border-radius:10px; background:var(--brand-50); border:1px solid var(--brand-100); font-size:0.86rem;"></div>
        </div>

        <div class="card">
            <div class="flex-between" style="margin-bottom: 0.75rem;">
                <h2 style="font-size: 1rem; margin: 0;">2. Chọn món ăn / đồ uống</h2>
                <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                    <select id="pos-category-filter" aria-label="Lọc danh mục POS" style="width: auto; padding: 0.35rem 0.6rem; font-size: 0.82rem;">
                        <option value="">Tất cả danh mục</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    <input id="pos-search-input" type="text" placeholder="Tìm món..." aria-label="Tìm món ăn POS" style="width: 170px; padding: 0.35rem 0.6rem; font-size: 0.82rem;">
                </div>
            </div>

            <div id="pos-products-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(175px, 1fr)); gap: 0.65rem;">
                @foreach ($products as $product)
                    @php
                        $stockQty = $product->stock_quantity;
                        $outOfStock = $stockQty !== null && $stockQty <= 0;
                    @endphp
                    <button
                        type="button"
                        class="pos-product-card"
                        data-id="{{ $product->id }}"
                        data-category="{{ $product->category_id }}"
                        data-name="{{ $product->name }}"
                        data-price="{{ $product->price }}"
                        data-stock="{{ $stockQty === null ? '' : $stockQty }}"
                        {{ $outOfStock ? 'disabled' : '' }}
                        style="text-align:left; background:{{ $outOfStock ? '#F9FAFB' : '#FFFFFF' }}; border:1px solid var(--border); border-radius:10px; padding:0.7rem; cursor:{{ $outOfStock ? 'not-allowed' : 'pointer' }}; opacity:{{ $outOfStock ? '0.6' : '1' }};"
                    >
                        <div style="font-weight:700; font-size:0.86rem; color:var(--text-main); margin-bottom:0.25rem;">{{ $product->name }}</div>
                        <div style="font-size:0.84rem; font-weight:800; color:var(--brand-700);">{{ number_format($product->price, 0, ',', '.') }} ₫</div>
                        <div class="pos-stock-label" style="font-size:0.74rem; color:var(--text-muted); margin-top:0.2rem;">
                            @if ($stockQty === null)
                                Không giới hạn kho
                            @elseif ($stockQty <= 0)
                                Hết hàng
                            @else
                                Còn {{ $stockQty }} phần
                            @endif
                        </div>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <div>
        <div class="card">
            <h2 style="font-size: 1rem; margin: 0 0 0.75rem;">3. Hóa đơn POS hiện tại</h2>

            <div style="margin-bottom: 0.75rem;">
                <label for="pos-payment-method" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Hình thức thanh toán</label>
                <select id="pos-payment-method">
                    <option value="STUDENT_CARD_POS">Quẹt thẻ học sinh (Trừ hạn mức + Ngân hàng)</option>
                    <option value="COUNTER_CASH">Tiền mặt tại quầy</option>
                </select>
            </div>

            <div id="pos-cart-empty" style="font-size: 0.85rem; color: var(--text-muted); padding: 1rem 0; text-align: center;">
                Chưa chọn món nào. Nhấn vào món bên trái để thêm vào hóa đơn.
            </div>
            <div id="pos-cart-items" style="display:none; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); padding: 0.4rem 0; margin-bottom: 0.75rem;"></div>

            <div class="flex-between" style="margin-bottom: 0.85rem;">
                <strong style="font-size: 0.95rem;">Tổng cộng</strong>
                <strong id="pos-cart-total" style="font-size: 1.25rem; color: var(--brand-700);">0 ₫</strong>
            </div>

            <div id="pos-checkout-error" role="alert" style="display:none; margin-bottom:0.75rem; padding:0.65rem 0.8rem; border-radius:8px; background:#FEF2F2; color:#991B1B; border:1px solid #FECACA; font-size:0.83rem; font-weight:600;"></div>
            <div id="pos-checkout-success" role="status" style="display:none; margin-bottom:0.75rem; padding:0.75rem 0.85rem; border-radius:8px; background:var(--brand-50); color:var(--brand-900); border:1px solid var(--brand-100); font-size:0.84rem;"></div>

            <div style="display: flex; gap: 0.5rem;">
                <button id="pos-checkout-btn" type="button" class="btn btn-primary" style="flex: 1;" disabled>Thanh toán POS</button>
                <button id="pos-clear-cart-btn" type="button" class="btn btn-secondary">Làm mới</button>
            </div>
        </div>

        <div class="card">
            <h2 style="font-size: 0.95rem; margin: 0 0 0.65rem;">Đơn POS gần đây hôm nay</h2>
            @if ($recentPosOrders->isEmpty())
                <p style="font-size: 0.83rem; color: var(--text-muted); margin: 0;">Chưa có giao dịch POS nào trong ngày.</p>
            @else
                <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.83rem;">
                    @foreach ($recentPosOrders as $posOrder)
                        <li style="padding: 0.45rem 0; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                            <div>
                                <strong>{{ $posOrder->code }}</strong>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">
                                    {{ $posOrder->studentCard?->student?->full_name ?? 'Khách tại quầy' }}
                                    · {{ number_format($posOrder->total_amount, 0, ',', '.') }} ₫
                                </div>
                            </div>
                            <a href="{{ route('admin.pos.receipt', $posOrder) }}" target="_blank" rel="noopener" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Biên lai</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const lookupUrl = @json(route('admin.pos.lookup'));
    const checkoutUrl = @json(route('admin.pos.checkout'));

    let selectedCard = null;
    const cart = new Map();
    let currentIdempotencyKey = makeIdempotencyKey();
    let isSubmitting = false;

    const cardInput = document.getElementById('pos-card-input');
    const lookupBtn = document.getElementById('pos-lookup-btn');
    const clearCardBtn = document.getElementById('pos-clear-card-btn');
    const cardError = document.getElementById('pos-card-error');
    const cardInfo = document.getElementById('pos-card-info');
    const catFilter = document.getElementById('pos-category-filter');
    const searchInput = document.getElementById('pos-search-input');
    const methodSelect = document.getElementById('pos-payment-method');
    const cartEmpty = document.getElementById('pos-cart-empty');
    const cartItemsEl = document.getElementById('pos-cart-items');
    const cartTotalEl = document.getElementById('pos-cart-total');
    const checkoutBtn = document.getElementById('pos-checkout-btn');
    const clearCartBtn = document.getElementById('pos-clear-cart-btn');
    const checkoutErr = document.getElementById('pos-checkout-error');
    const checkoutOk = document.getElementById('pos-checkout-success');

    function makeIdempotencyKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return 'pos-' + window.crypto.randomUUID();
        }
        return 'pos-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    }

    function fmtVnd(amount) {
        return new Intl.NumberFormat('vi-VN').format(Number(amount || 0)) + ' ₫';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = String(text ?? '');
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function resetIdempotency() {
        currentIdempotencyKey = makeIdempotencyKey();
    }

    function renderCard() {
        if (!selectedCard) {
            cardInfo.style.display = 'none';
            cardInfo.innerHTML = '';
            return;
        }
        const st = selectedCard.student || {};
        const lim = selectedCard.spending_limit || {};
        const bankText = selectedCard.is_bank_linked && selectedCard.bank_summary
            ? escapeHtml(selectedCard.bank_summary.bank_name) + ' (' + escapeHtml(selectedCard.bank_summary.account_number_masked) + ')'
            : 'Chưa liên kết ngân hàng';
        cardInfo.style.display = 'block';
        cardInfo.innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">' +
                '<strong>' + escapeHtml(st.full_name) + ' · Lớp ' + escapeHtml(st.class_name) + '</strong>' +
                '<span class="badge badge-' + escapeHtml(selectedCard.status) + '">' + escapeHtml(selectedCard.status) + '</span>' +
            '</div>' +
            '<div style="font-size:0.78rem;color:#374151;margin-top:0.25rem;">' +
                'Thẻ: <strong>' + escapeHtml(selectedCard.card_code) + '</strong> · ' + escapeHtml(st.school_name) + ' · ' + bankText +
            '</div>' +
            '<div style="margin-top:0.45rem;font-weight:700;color:var(--brand-700);">' +
                'Hạn mức còn lại hôm nay: ' + fmtVnd(lim.remaining_limit) +
                ' <span style="font-weight:400;color:#4B5563;">(Đã chi ' + fmtVnd(lim.spent_today) + ' / ' + fmtVnd(lim.daily_limit) + ')</span>' +
            '</div>';
    }

    function renderCart() {
        const entries = Array.from(cart.values());
        if (entries.length === 0) {
            cartEmpty.style.display = 'block';
            cartItemsEl.style.display = 'none';
            cartItemsEl.innerHTML = '';
            cartTotalEl.textContent = fmtVnd(0);
            checkoutBtn.disabled = true;
            return;
        }
        cartEmpty.style.display = 'none';
        cartItemsEl.style.display = 'block';
        let total = 0;
        cartItemsEl.innerHTML = entries.map((item) => {
            total += item.price * item.quantity;
            return '<div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;padding:0.4rem 0;font-size:0.84rem;">' +
                '<div style="min-width:0;flex:1;"><div style="font-weight:600;">' + escapeHtml(item.name) + '</div>' +
                '<div style="font-size:0.75rem;color:#4B5563;">' + fmtVnd(item.price) + ' × ' + item.quantity + '</div></div>' +
                '<div style="display:flex;align-items:center;gap:0.25rem;">' +
                    '<button type="button" class="btn btn-secondary pos-qty-dec" data-id="' + item.id + '" style="padding:0.15rem 0.45rem;">−</button>' +
                    '<span style="min-width:20px;text-align:center;font-weight:700;">' + item.quantity + '</span>' +
                    '<button type="button" class="btn btn-secondary pos-qty-inc" data-id="' + item.id + '" style="padding:0.15rem 0.45rem;">+</button>' +
                '</div></div>';
        }).join('');
        cartTotalEl.textContent = fmtVnd(total);
        checkoutBtn.disabled = isSubmitting || entries.length === 0;

        cartItemsEl.querySelectorAll('.pos-qty-dec').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (isSubmitting) return;
                const id = Number(btn.getAttribute('data-id'));
                const it = cart.get(id);
                if (!it) return;
                if (it.quantity <= 1) cart.delete(id);
                else it.quantity -= 1;
                resetIdempotency();
                renderCart();
            });
        });
        cartItemsEl.querySelectorAll('.pos-qty-inc').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (isSubmitting) return;
                const id = Number(btn.getAttribute('data-id'));
                const it = cart.get(id);
                if (!it) return;
                const maxAllowed = it.stock !== null ? Math.min(50, it.stock) : 50;
                if (it.quantity >= maxAllowed) return;
                it.quantity += 1;
                resetIdempotency();
                renderCart();
            });
        });
    }

    async function doLookup(queryOverride) {
        const q = (typeof queryOverride === 'string' ? queryOverride : cardInput.value).trim();
        cardError.style.display = 'none';
        if (!q) {
            cardError.textContent = 'Vui lòng nhập mã thẻ hoặc mã học sinh.';
            cardError.style.display = 'block';
            return;
        }
        try {
            const res = await fetch(lookupUrl + '?q=' + encodeURIComponent(q), {
                headers: { 'Accept': 'application/json' },
            });
            const isJson = (res.headers.get('content-type') || '').includes('application/json');
            const body = isJson ? await res.json() : {};
            if (!res.ok) {
                selectedCard = null;
                resetIdempotency();
                renderCard();
                cardError.textContent = body.message || (res.status === 419 ? 'Phiên đăng nhập hết hạn. Vui lòng tải lại trang.' : 'Không tìm thấy thẻ.');
                cardError.style.display = 'block';
                return;
            }
            selectedCard = body.data;
            resetIdempotency();
            renderCard();
        } catch {
            cardError.textContent = 'Lỗi kết nối khi tra cứu thẻ.';
            cardError.style.display = 'block';
        }
    }

    lookupBtn.addEventListener('click', () => doLookup());
    cardInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            doLookup();
        }
    });
    clearCardBtn.addEventListener('click', () => {
        if (isSubmitting) return;
        selectedCard = null;
        cardInput.value = '';
        cardError.style.display = 'none';
        resetIdempotency();
        renderCard();
    });
    methodSelect.addEventListener('change', () => {
        resetIdempotency();
        checkoutErr.style.display = 'none';
    });

    function applyProductFilter() {
        const cat = catFilter.value;
        const term = searchInput.value.trim().toLowerCase();
        document.querySelectorAll('.pos-product-card').forEach((card) => {
            const matchCat = !cat || card.getAttribute('data-category') === cat;
            const matchTerm = !term || (card.getAttribute('data-name') || '').toLowerCase().includes(term);
            card.style.display = (matchCat && matchTerm) ? '' : 'none';
        });
    }
    catFilter.addEventListener('change', applyProductFilter);
    searchInput.addEventListener('input', applyProductFilter);

    document.querySelectorAll('.pos-product-card').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (isSubmitting) return;
            const id = Number(btn.getAttribute('data-id'));
            const name = btn.getAttribute('data-name') || '';
            const price = Number(btn.getAttribute('data-price') || 0);
            const rawStock = btn.getAttribute('data-stock');
            const stock = rawStock === '' ? null : Number(rawStock);
            const maxAllowed = stock !== null ? Math.min(50, stock) : 50;
            const existing = cart.get(id);
            if (existing) {
                if (existing.quantity >= maxAllowed) return;
                existing.quantity += 1;
            } else {
                if (maxAllowed <= 0) return;
                cart.set(id, { id, name, price, stock, quantity: 1 });
            }
            checkoutErr.style.display = 'none';
            resetIdempotency();
            renderCart();
        });
    });

    clearCartBtn.addEventListener('click', () => {
        if (isSubmitting) return;
        cart.clear();
        checkoutErr.style.display = 'none';
        checkoutOk.style.display = 'none';
        resetIdempotency();
        renderCart();
    });

    checkoutBtn.addEventListener('click', async () => {
        if (isSubmitting) return;
        checkoutErr.style.display = 'none';
        checkoutOk.style.display = 'none';

        const method = methodSelect.value;
        if (method === 'STUDENT_CARD_POS' && !selectedCard) {
            checkoutErr.textContent = 'Vui lòng tra cứu thẻ học sinh trước khi thanh toán quẹt thẻ.';
            checkoutErr.style.display = 'block';
            return;
        }
        const items = Array.from(cart.values()).map((it) => ({
            product_id: it.id,
            quantity: it.quantity,
        }));
        if (items.length === 0) return;

        isSubmitting = true;
        checkoutBtn.disabled = true;
        const checkedOutCardCode = selectedCard ? selectedCard.card_code : null;
        try {
            const res = await fetch(checkoutUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    payment_method: method,
                    student_card_id: method === 'STUDENT_CARD_POS' ? selectedCard.id : null,
                    idempotency_key: currentIdempotencyKey,
                    items,
                }),
            });
            const isJson = (res.headers.get('content-type') || '').includes('application/json');
            const body = isJson ? await res.json() : {};
            if (!res.ok) {
                checkoutErr.textContent = body.message || (res.status === 419 ? 'Phiên đăng nhập hết hạn. Vui lòng tải lại trang.' : 'Thanh toán POS thất bại.');
                checkoutErr.style.display = 'block';
                isSubmitting = false;
                checkoutBtn.disabled = cart.size === 0;
                return;
            }

            const data = body.data;
            if (data.stock_snapshot) {
                Object.entries(data.stock_snapshot).forEach(([pid, qty]) => {
                    const cardEl = document.querySelector('.pos-product-card[data-id="' + pid + '"]');
                    if (!cardEl) return;
                    cardEl.setAttribute('data-stock', qty === null ? '' : String(qty));
                    const lbl = cardEl.querySelector('.pos-stock-label');
                    if (lbl) {
                        lbl.textContent = qty === null ? 'Không giới hạn kho' : (qty <= 0 ? 'Hết hàng' : 'Còn ' + qty + ' phần');
                    }
                    if (qty !== null && Number(qty) <= 0) {
                        cardEl.setAttribute('disabled', 'disabled');
                        cardEl.style.opacity = '0.6';
                    }
                });
            }

            cart.clear();
            isSubmitting = false;
            resetIdempotency();
            renderCart();

            if (checkedOutCardCode && data.remaining_limit_snapshot !== null && data.remaining_limit_snapshot !== undefined) {
                await doLookup(checkedOutCardCode);
            }

            checkoutOk.style.display = 'block';
            checkoutOk.innerHTML =
                '<strong>Thanh toán thành công: ' + escapeHtml(data.code) + '</strong> (' + fmtVnd(data.total_amount) + ')<br>' +
                '<a href="' + escapeHtml(data.receipt_url) + '" target="_blank" rel="noopener" class="btn btn-primary" style="margin-top:0.5rem;">Xem &amp; In biên lai POS →</a>';
        } catch {
            checkoutErr.textContent = 'Lỗi mạng khi gửi đơn POS. Vui lòng thử lại.';
            checkoutErr.style.display = 'block';
            isSubmitting = false;
            checkoutBtn.disabled = cart.size === 0;
        }
    });
})();
</script>
@endsection
