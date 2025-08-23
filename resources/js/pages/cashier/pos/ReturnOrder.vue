<script setup>
import CashierLayout from '@/layouts/CashierLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3'; // Không cần import 'router as Inertia' nữa
import { ref, watch, computed } from 'vue';
import { Search, ChevronDown, ChevronUp, ArrowLeft } from 'lucide-vue-next';
import axios from 'axios';
import { debounce } from 'lodash';

const searchQuery = ref('');
const bill = ref(null);
const loading = ref(false);
const error = ref('');
const successMessage = ref('');
const selectedItems = ref({});

const searchBill = async () => {
    if (!searchQuery.value) {
        bill.value = null;
        error.value = 'Vui lòng nhập số hóa đơn hoặc số điện thoại.';
        successMessage.value = '';
        return;
    }

    loading.value = true;
    error.value = '';
    successMessage.value = '';
    try {
        const response = await axios.get(route('cashier.returns.search'), {
            params: { query: searchQuery.value }
        });
        bill.value = response.data;
        if (bill.value) {
            selectedItems.value = {};
            bill.value.details.forEach(detail => {
                if (!selectedItems.value[detail.product_id]) {
                    selectedItems.value[detail.product_id] = {
                        productId: detail.product_id,
                        quantity: 0
                    };
                }
            });
        }
    } catch (err) {
        if (err.response && err.response.data && err.response.data.error) {
            error.value = err.response.data.error;
        } else {
            error.value = 'Đã xảy ra lỗi khi tìm kiếm hóa đơn.';
        }
        bill.value = null;
    } finally {
        loading.value = false;
    }
};

const debouncedSearch = debounce(searchBill, 500);

watch(searchQuery, (newQuery) => {
    debouncedSearch();
});

const returnForm = useForm({
    bill_id: null,
    return_items: [],
    reason: ''
});

const returnBill = () => {
    if (!bill.value) {
        error.value = 'Không có hóa đơn để xử lý.';
        return;
    }

    const itemsToReturn = Object.values(selectedItems.value)
        .filter(item => item.quantity > 0)
        .map(item => ({
            product_id: item.productId,
            quantity: item.quantity
        }));

    if (itemsToReturn.length === 0) {
        error.value = 'Vui lòng chọn ít nhất một sản phẩm để trả lại.';
        return;
    }

    returnForm.bill_id = bill.value.id;
    returnForm.return_items = itemsToReturn;
    
    // Sử dụng form.post thay vì axios.post
    // Inertia sẽ tự động chuyển hướng khi controller trả về redirect
    returnForm.post(route('cashier.returns.process'), {
        onSuccess: () => {
            // Logic này sẽ chạy sau khi chuyển hướng thành công
            // Ví dụ: có thể hiển thị một thông báo flash message nếu bạn đã thiết lập ở backend
        },
        onError: (errors) => {
            // Xử lý các lỗi validation từ controller
            if (errors && errors.error) {
                error.value = errors.error;
            } else {
                error.value = 'Đã xảy ra lỗi khi xử lý trả hàng.';
            }
        }
    });
};

const totalAmountReturned = computed(() => {
    if (!bill.value) return 0;
    let total = 0;
    Object.values(selectedItems.value).forEach(item => {
        const originalDetail = bill.value.details.find(d => d.product_id === item.productId);
        if (originalDetail) {
            total += item.quantity * originalDetail.unit_price;
        }
    });
    return total;
});

const formatCurrency = (value) => {
    if (value == null) return '0₫';
    return new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND',
    }).format(value);
};

const formatDate = (dateString) => {
    const date = new Date(dateString);
    return date.toLocaleString('vi-VN', {
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
    });
};

const groupedBillDetails = computed(() => {
    if (!bill.value) return [];

    const groups = {};
    bill.value.details.forEach(detail => {
        if (!groups[detail.product_id]) {
            groups[detail.product_id] = {
                product: detail.product,
                quantity: 0,
                unit_price: detail.unit_price,
            };
        }
        groups[detail.product_id].quantity += detail.quantity;
    });

    return Object.values(groups);
});
</script>
<template>
    <Head title="Tạo đơn trả hàng" />
    <CashierLayout>
        <div class="p-6 max-w-7xl mx-auto">
            <div class="flex items-center mb-6">
                <Link :href="route('cashier.returns.list')" class="flex items-center text-gray-600 hover:text-gray-800 transition-colors mr-4">
                    <ArrowLeft class="w-6 h-6 mr-1" />
                </Link>
                <h1 class="text-2xl font-bold text-gray-800">Tạo đơn trả hàng</h1>
            </div>

            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-bold text-gray-700 mb-4">Tìm kiếm hóa đơn gốc</h2>
                <div class="flex items-center gap-4 mb-4">
                    <div class="relative flex-1">
                        <Search class="absolute left-4 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-500" />
                        <input v-model="searchQuery" type="text" placeholder="Nhập mã hóa đơn"
                            class="w-full p-3 pl-12 pr-4 bg-gray-50 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-200" />
                    </div>
                    <div v-if="loading" class="text-blue-500">Đang tìm...</div>
                </div>
                <div v-if="error" class="mt-4 text-red-500 font-semibold">{{ error }}</div>
                <div v-if="successMessage" class="mt-4 text-green-500 font-semibold">{{ successMessage }}</div>
            </div>

            <div v-if="bill" class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold text-gray-700 mb-4">Thông tin hóa đơn #{{ bill.bill_number }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 text-gray-600">
                    <div>
                        <p><strong>Ngày tạo:</strong> {{ formatDate(bill.created_at) }}</p>
                        <p><strong>Khách hàng:</strong> {{ bill.customer?.name || 'Khách lẻ' }}</p>
                        <p><strong>Số điện thoại:</strong> {{ bill.customer?.phone || 'N/A' }}</p>
                    </div>
                    <div>
                        <p><strong>Tổng tiền:</strong> {{ formatCurrency(bill.total_amount) }}</p>
                        <p><strong>Trạng thái trả hàng:</strong>
                            <span v-if="bill.return_status.has_been_returned" class="text-red-500 font-semibold">Đã được
                                trả lại</span>
                            <span v-else-if="bill.return_status.is_expired" class="text-red-500 font-semibold">Đã quá 24
                                giờ</span>
                            <span v-else class="text-green-500 font-semibold">Có thể trả lại</span>
                        </p>
                    </div>
                </div>

                <h3 class="font-bold text-lg mb-2">Chi tiết sản phẩm</h3>
                <div class="overflow-x-auto mb-4">
                    <table class="min-w-full border-collapse">
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="p-3 text-left text-sm font-semibold text-gray-600">Sản phẩm</th>
                                <th class="p-3 text-left text-sm font-semibold text-gray-600">Giá</th>
                                <th class="p-3 text-left text-sm font-semibold text-gray-600">Số lượng mua</th>
                                <th class="p-3 text-left text-sm font-semibold text-gray-600">Số lượng trả</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in groupedBillDetails" :key="item.product.id" class="border-t">
                                <td class="p-3">{{ item.product?.name }}</td>
                                <td class="p-3">{{ formatCurrency(item.unit_price) }}</td>
                                <td class="p-3">{{ item.quantity }}</td>
                                <td class="p-3">
                                    <input 
                                        type="number" 
                                        v-model.number="selectedItems[item.product.id].quantity"
                                        :max="item.quantity"
                                        min="0"
                                        class="w-20 p-1 border rounded"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mb-4">
                    <label for="reason" class="block text-sm font-medium text-gray-700 mb-2">Lý do trả hàng</label>
                    <textarea id="reason" v-model="returnForm.reason" rows="3"
                        placeholder="Nhập lý do khách hàng muốn trả hàng..."
                        class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-200"></textarea>
                </div>

                <div class="flex flex-col sm:flex-row justify-between items-center bg-gray-100 p-4 rounded-lg">
                    <div class="text-lg font-bold">
                        Tổng tiền hoàn trả: <span class="text-blue-600">{{ formatCurrency(totalAmountReturned) }}</span>
                    </div>
                    <button @click="returnBill" :disabled="!bill.can_be_returned || totalAmountReturned === 0" :class="{
                        'bg-green-600 hover:bg-green-700': bill.can_be_returned,
                        'bg-gray-400 cursor-not-allowed': !bill.can_be_returned || totalAmountReturned === 0
                    }" class="px-6 py-3 text-white rounded-lg font-semibold transition-colors mt-4 sm:mt-0">
                        Xử lý trả hàng
                    </button>
                </div>
            </div>
        </div>
    </CashierLayout>
</template>