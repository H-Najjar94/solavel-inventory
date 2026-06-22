import React from 'react';
import { createBrowserRouter, Navigate } from 'react-router-dom';
import AppShell from '../layouts/AppShell.jsx';
import DashboardPage from '../pages/DashboardPage.jsx';
import ItemsPage from '../pages/ItemsPage.jsx';
import ItemDetailPage from '../pages/ItemDetailPage.jsx';
import ItemFormPage from '../pages/ItemFormPage.jsx';
import WarehousesPage from '../pages/WarehousesPage.jsx';
import WarehouseFormPage from '../pages/WarehouseFormPage.jsx';
import WarehouseDetailPage from '../pages/WarehouseDetailPage.jsx';
import SuppliersPage from '../pages/SuppliersPage.jsx';
import SupplierFormPage from '../pages/SupplierFormPage.jsx';
import SupplierDetailPage from '../pages/SupplierDetailPage.jsx';
import BalancesPage from '../pages/BalancesPage.jsx';
import OpeningStockPage from '../pages/OpeningStockPage.jsx';
import OpeningStockFormPage from '../pages/OpeningStockFormPage.jsx';
import OpeningStockDetailPage from '../pages/OpeningStockDetailPage.jsx';
import AdjustmentsPage from '../pages/AdjustmentsPage.jsx';
import AdjustmentFormPage from '../pages/AdjustmentFormPage.jsx';
import AdjustmentDetailPage from '../pages/AdjustmentDetailPage.jsx';
import LedgerPage from '../pages/LedgerPage.jsx';
import PurchaseOrdersPage from '../pages/PurchaseOrdersPage.jsx';
import PurchaseOrderFormPage from '../pages/PurchaseOrderFormPage.jsx';
import PurchaseOrderDetailPage from '../pages/PurchaseOrderDetailPage.jsx';
import GoodsReceiptsPage from '../pages/GoodsReceiptsPage.jsx';
import GoodsReceiptFormPage from '../pages/GoodsReceiptFormPage.jsx';
import GoodsReceiptDetailPage from '../pages/GoodsReceiptDetailPage.jsx';
import TransfersPage from '../pages/TransfersPage.jsx';
import TransferFormPage from '../pages/TransferFormPage.jsx';
import TransferDetailPage from '../pages/TransferDetailPage.jsx';
import CountsPage from '../pages/CountsPage.jsx';
import CountFormPage from '../pages/CountFormPage.jsx';
import CountDetailPage from '../pages/CountDetailPage.jsx';
import SalesOrdersPage from '../pages/SalesOrdersPage.jsx';
import SalesOrderFormPage from '../pages/SalesOrderFormPage.jsx';
import SalesOrderDetailPage from '../pages/SalesOrderDetailPage.jsx';
import PickListsPage from '../pages/PickListsPage.jsx';
import PickListDetailPage from '../pages/PickListDetailPage.jsx';
import PacksPage from '../pages/PacksPage.jsx';
import PackDetailPage from '../pages/PackDetailPage.jsx';
import ShipmentsPage from '../pages/ShipmentsPage.jsx';
import ShipmentDetailPage from '../pages/ShipmentDetailPage.jsx';
import SalesReturnsPage from '../pages/SalesReturnsPage.jsx';
import SalesReturnFormPage from '../pages/SalesReturnFormPage.jsx';
import SalesReturnDetailPage from '../pages/SalesReturnDetailPage.jsx';
import TraceabilityPage from '../pages/TraceabilityPage.jsx';
import LotsPage from '../pages/LotsPage.jsx';
import LotDetailPage from '../pages/LotDetailPage.jsx';
import SerialsPage from '../pages/SerialsPage.jsx';
import SerialDetailPage from '../pages/SerialDetailPage.jsx';
import RecallsPage from '../pages/RecallsPage.jsx';
import RecallFormPage from '../pages/RecallFormPage.jsx';
import RecallDetailPage from '../pages/RecallDetailPage.jsx';
import ReportsPage from '../pages/ReportsPage.jsx';
import SettingsPage from '../pages/SettingsPage.jsx';
import IntegrationSettingsPage from '../pages/IntegrationSettingsPage.jsx';
import IntegrationEventsPage from '../pages/IntegrationEventsPage.jsx';
import NotFoundPage from '../pages/NotFoundPage.jsx';
// Onboarding/activation lives entirely in the CENTRAL app
// (https://solavel.com/inventory/onboarding). Apache routes that path away from
// this SPA, so the former in-app OnboardingPage is intentionally not wired up.

// The SPA is mounted by Blade at /inventory/dashboard; client routes live under
// the /inventory basename so deep links resolve under the Apache mount.
export const router = createBrowserRouter(
    [
        {
            path: '/',
            element: <AppShell />,
            children: [
                { index: true, element: <Navigate to="/dashboard" replace /> },
                { path: 'dashboard', element: <DashboardPage /> },
                { path: 'items', element: <ItemsPage /> },
                { path: 'items/new', element: <ItemFormPage /> },
                { path: 'items/:id', element: <ItemDetailPage /> },
                { path: 'items/:id/edit', element: <ItemFormPage /> },
                { path: 'warehouses', element: <WarehousesPage /> },
                { path: 'warehouses/new', element: <WarehouseFormPage /> },
                { path: 'warehouses/:id', element: <WarehouseDetailPage /> },
                { path: 'warehouses/:id/edit', element: <WarehouseFormPage /> },
                { path: 'suppliers', element: <SuppliersPage /> },
                { path: 'suppliers/new', element: <SupplierFormPage /> },
                { path: 'suppliers/:id', element: <SupplierDetailPage /> },
                { path: 'suppliers/:id/edit', element: <SupplierFormPage /> },
                { path: 'balances', element: <BalancesPage /> },
                { path: 'opening-stock', element: <OpeningStockPage /> },
                { path: 'opening-stock/new', element: <OpeningStockFormPage /> },
                { path: 'opening-stock/:id', element: <OpeningStockDetailPage /> },
                { path: 'opening-stock/:id/edit', element: <OpeningStockFormPage /> },
                { path: 'adjustments', element: <AdjustmentsPage /> },
                { path: 'adjustments/new', element: <AdjustmentFormPage /> },
                { path: 'adjustments/:id', element: <AdjustmentDetailPage /> },
                { path: 'adjustments/:id/edit', element: <AdjustmentFormPage /> },
                { path: 'transfers', element: <TransfersPage /> },
                { path: 'transfers/new', element: <TransferFormPage /> },
                { path: 'transfers/:id', element: <TransferDetailPage /> },
                { path: 'transfers/:id/edit', element: <TransferFormPage /> },
                { path: 'counts', element: <CountsPage /> },
                { path: 'counts/new', element: <CountFormPage /> },
                { path: 'counts/:id', element: <CountDetailPage /> },
                { path: 'counts/:id/edit', element: <CountFormPage /> },
                { path: 'purchase-orders', element: <PurchaseOrdersPage /> },
                { path: 'purchase-orders/new', element: <PurchaseOrderFormPage /> },
                { path: 'purchase-orders/:id', element: <PurchaseOrderDetailPage /> },
                { path: 'purchase-orders/:id/edit', element: <PurchaseOrderFormPage /> },
                { path: 'goods-receipts', element: <GoodsReceiptsPage /> },
                { path: 'goods-receipts/new', element: <GoodsReceiptFormPage /> },
                { path: 'goods-receipts/from-po/:poId', element: <GoodsReceiptFormPage /> },
                { path: 'goods-receipts/:id', element: <GoodsReceiptDetailPage /> },
                { path: 'goods-receipts/:id/edit', element: <GoodsReceiptFormPage /> },
                { path: 'sales-orders', element: <SalesOrdersPage /> },
                { path: 'sales-orders/new', element: <SalesOrderFormPage /> },
                { path: 'sales-orders/:id', element: <SalesOrderDetailPage /> },
                { path: 'sales-orders/:id/edit', element: <SalesOrderFormPage /> },
                { path: 'pick-lists', element: <PickListsPage /> },
                { path: 'pick-lists/:id', element: <PickListDetailPage /> },
                { path: 'packs', element: <PacksPage /> },
                { path: 'packs/:id', element: <PackDetailPage /> },
                { path: 'shipments', element: <ShipmentsPage /> },
                { path: 'shipments/:id', element: <ShipmentDetailPage /> },
                { path: 'sales-returns', element: <SalesReturnsPage /> },
                { path: 'sales-returns/new', element: <SalesReturnFormPage /> },
                { path: 'sales-returns/:id', element: <SalesReturnDetailPage /> },
                { path: 'sales-returns/:id/edit', element: <SalesReturnFormPage /> },
                { path: 'traceability', element: <TraceabilityPage /> },
                { path: 'traceability/lots', element: <LotsPage /> },
                { path: 'traceability/lots/:id', element: <LotDetailPage /> },
                { path: 'traceability/serials', element: <SerialsPage /> },
                { path: 'traceability/serials/:id', element: <SerialDetailPage /> },
                { path: 'recalls', element: <RecallsPage /> },
                { path: 'recalls/new', element: <RecallFormPage /> },
                { path: 'recalls/:id', element: <RecallDetailPage /> },
                { path: 'reports', element: <ReportsPage /> },
                { path: 'settings', element: <SettingsPage /> },
                { path: 'settings/solabooks', element: <IntegrationSettingsPage /> },
                { path: 'integrations/solabooks/events', element: <IntegrationEventsPage /> },
                { path: 'ledger', element: <LedgerPage /> },
                { path: '*', element: <NotFoundPage /> },
            ],
        },
    ],
    { basename: '/inventory' }
);
