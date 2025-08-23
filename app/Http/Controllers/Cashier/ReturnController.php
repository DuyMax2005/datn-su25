<?php

namespace App\Http\Controllers\Cashier;

use App\Models\BatchItem;
use App\Models\Bill;
use App\Models\ReturnBill;
use App\Models\ReturnBillDetail;
use App\Models\Product;
use App\Models\Batch;
use App\Models\Customer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Carbon\Carbon;
use Inertia\Inertia;
use App\Models\User;

class ReturnController extends Controller
{
        public function index()
    {
        return Inertia::render('cashier/pos/ReturnOrder');
    }
    
    public function list()
    {
        return Inertia::render('cashier/pos/ReturnList');
    }

    public function getAll(Request $request)
    {
        $query = $request->input('query');
        $date = $request->input('date');
        $perPage = 5;

        $returnBills = ReturnBill::with(['bill', 'details.product', 'cashier:id,name'])
            ->when($query, function ($q, $query) {
                $q->where('return_bill_number', 'like', '%' . $query . '%')
                    ->orWhereHas('bill', function ($b) use ($query) {
                        $b->where('bill_number', 'like', '%' . $query . '%');
                    });
            })
            ->when($date, function ($q, $date) {
                $q->whereDate('created_at', $date);
            })
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($returnBills);
    }
    
    public function search(Request $request)
    {
        $query = $request->input('query');

        if (!$query) {
            return response()->json(['error' => 'Vui lòng nhập số hóa đơn.'], 400);
        }

        $bill = Bill::with(['customer', 'details.product', 'details.batch', 'returnBills'])
            ->where('bill_number', $query)
            ->orWhereHas('customer', function ($q) use ($query) {
                $q->where('phone', $query);
            })
            ->first();

        if (!$bill) {
            return response()->json(['error' => 'Không tìm thấy hóa đơn nào.'], 404);
        }

        $isWithin24Hours = $bill->created_at->diffInHours(Carbon::now()) <= 24;
        $hasBeenReturned = $bill->returnBills->isNotEmpty();

        $billData = $bill->toArray();
        $billData['can_be_returned'] = $isWithin24Hours && !$hasBeenReturned;
        $billData['return_status'] = [
            'has_been_returned' => $hasBeenReturned,
            'is_expired' => !$isWithin24Hours,
        ];
        
        return response()->json($billData);
    }

public function processReturn(Request $request)
    {
        try {
            $validated = $request->validate([
                'bill_id' => 'required|exists:bills,id',
                'return_items' => 'required|array',
                'return_items.*.product_id' => 'required|exists:products,id',
                'return_items.*.quantity' => 'required|integer|min:1',
                'reason' => 'nullable|string|max:255',
            ]);

            DB::transaction(function () use ($validated) {
                $bill = Bill::with('details')->findOrFail($validated['bill_id']);
                $totalAmountReturned = 0;

                $isWithin24Hours = $bill->created_at->diffInHours(Carbon::now()) <= 24;
                if (!$isWithin24Hours || $bill->returnBills->isNotEmpty()) {
                    throw new \Exception('Hóa đơn không đủ điều kiện để trả hàng.');
                }
                
                $returnBill = ReturnBill::create([
                    'return_bill_number' => 'RT' . now()->format('YmdHis') . rand(100, 999),
                    'bill_id' => $bill->id,
                    'customer_id' => $bill->customer_id,
                    'cashier_id' => auth()->user()->id,
                    'total_amount_returned' => 0,
                    'reason' => $validated['reason'],
                ]);
                
                foreach ($validated['return_items'] as $item) {
                    $productId = $item['product_id'];
                    $quantityToReturn = $item['quantity'];
                    
                    $billDetailsForProduct = $bill->details->where('product_id', $productId);
                    
                    if ($billDetailsForProduct->isEmpty()) {
                        Log::warning("Sản phẩm có ID {$productId} không tồn tại trong hóa đơn #{$bill->id}.");
                        continue;
                    }
                    
                    $totalQuantityInBill = $billDetailsForProduct->sum('quantity');
                    if ($quantityToReturn > $totalQuantityInBill) {
                         throw new \Exception("Số lượng trả lại của sản phẩm {$billDetailsForProduct->first()->p_name} vượt quá tổng số lượng đã mua.");
                    }

                    $remainingToReturn = $quantityToReturn;
                    foreach ($billDetailsForProduct as $billDetail) {
                        if ($remainingToReturn <= 0) {
                            break;
                        }

                        $quantityInThisDetail = $billDetail->quantity;
                        $actualReturnQuantity = min($remainingToReturn, $quantityInThisDetail);
                        
                        if ($actualReturnQuantity > 0) {
                            $subtotal = $actualReturnQuantity * $billDetail->unit_price;
                            $totalAmountReturned += $subtotal;

                            ReturnBillDetail::create([
                                'return_bill_id' => $returnBill->id,
                                'product_id' => $billDetail->product_id,
                                'p_name' => $billDetail->p_name,
                                'returned_quantity' => $actualReturnQuantity,
                                'unit_price' => $billDetail->unit_price,
                                'subtotal' => $subtotal,
                            ]);

                            $product = Product::findOrFail($billDetail->product_id);
                            $product->increment('stock_quantity', $actualReturnQuantity);

                            $batchItem = BatchItem::where('batch_id', $billDetail->batch_id)
                                ->where('product_id', $billDetail->product_id)
                                ->first();

                            $batchItem->update(['inventory_status' => 'active']);
                                
                            if ($batchItem) {
                                $batchItem->increment('current_quantity', $actualReturnQuantity);
                            }

                            $remainingToReturn -= $actualReturnQuantity;
                        }
                    }
                }
                
                $returnBill->update(['total_amount_returned' => $totalAmountReturned]);
            });

            // Chuyển hướng đến trang danh sách sau khi thành công
            return redirect()->route('cashier.returns.list')->with('success', 'Xử lý trả hàng thành công!');
            
        } catch (ModelNotFoundException $e) {
            // Sử dụng back() để trả về trang trước với thông báo lỗi
            return redirect()->back()->withErrors(['error' => 'Hóa đơn hoặc sản phẩm không tồn tại.']);
        } catch (\Exception $e) {
            Log::error("Lỗi khi xử lý trả hàng: " . $e->getMessage());
            // Sử dụng back() để trả về trang trước với thông báo lỗi
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}