# Farmlet Build Requirements Tracker

## 1. Purpose
This document is the build tracker for implementing the Farmlet county-based marketplace and delivery workflow.

## 2. Primary Reference
- Source guide: `/home/talai/Data/farmlet/FARMLET CHANGES GUIDE.pdf`
- Product-owner clarification:
- Kenya-only scope.
- Escrow is a simulation of internal holding and release strategy.

## 3. Build Checklist

### 3.1 Scope and Architecture
- [x] 3.1.1 Enforce Kenya-only geography for this rollout.
- [x] 3.1.2 Enforce County -> Sub-County -> Ward hierarchy across core flows.
- [x] 3.1.3 Enforce actor location binding (customer, vendor/farmer, driver, processing manager).
- [x] 3.1.4 Block cross-sub-county orders in Phase 1.
- [x] 3.1.5 Keep escrow implementation as internal ledger simulation (not external custody).

### 3.2 Roles
- [x] 3.2.1 Ensure `customer` role behavior is complete.
- [x] 3.2.2 Ensure `vendor` behavior is complete.
- [x] 3.2.3 Ensure `farmer` behavior is complete.
- [x] 3.2.4 Ensure `driver` behavior is complete.
- [x] 3.2.5 Ensure `processing_manager` behavior is complete.
- [x] 3.2.6 Ensure `admin` behavior is complete.

### 3.3 County-Based Unique IDs
- [x] 3.3.1 Generate human-readable IDs for Vendor/Farmer/Driver.
- [x] 3.3.2 Use per-county and per-role sequencing.
- [x] 3.3.3 Enforce format: `CCC-XXX-CODE`.

### 3.4 Onboarding Workflows
- [x] 3.4.1 Vendor/Farmer onboarding captures seller type, location, processing support, and approval status.
- [x] 3.4.2 Driver onboarding captures location, vehicle type, and license/ID proof.
- [x] 3.4.3 Customer onboarding + address binding uses Kenya hierarchy data.
- [x] 3.4.4 Validate Kenya MPESA-compatible phone format.

### 3.5 Product Model (Raw vs Processed)
- [x] 3.5.1 Add product-level processing support fields.
- [x] 3.5.2 Support customer choice of `raw` vs `processed` where available.
- [x] 3.5.3 Enforce `processed_price > raw_price`.

### 3.6 Order Model and Creation Logic
- [x] 3.6.1 Persist order type and location bindings.
- [x] 3.6.2 Auto-filter eligible sellers/farmers by matching sub-county.
- [x] 3.6.3 Auto-filter eligible drivers by matching sub-county.
- [x] 3.6.4 Assign processing room automatically for processed orders.

### 3.7 Processed Workflow
- [x] 3.7.1 Vendor prep stage implemented.
- [x] 3.7.2 Driver pickup-for-processing stage implemented.
- [x] 3.7.3 Processing-room receipt confirmation implemented.
- [x] 3.7.4 Ready-for-delivery confirmation by processing manager implemented.
- [x] 3.7.5 Final customer delivery stage implemented.

### 3.8 Notifications (Event-Based)
- [x] 3.8.1 Implement event-based order notification triggers.
- [x] 3.8.2 Implement app notification channel coverage.
- [x] 3.8.3 Implement email notification channel coverage.
- [x] 3.8.4 Implement SMS/WhatsApp notification channel coverage.
- [x] 3.8.5 Persist every event into notification log.

### 3.9 Delivery Confirmation and Simulated Escrow Release
- [ ] 3.9.1 Require customer confirmation of receipt.
- [ ] 3.9.2 Require driver confirmation of delivery.
- [ ] 3.9.3 Release seller settlement only after confirmation rules are met.

### 3.10 Payment Split Logic (Simulated Escrow)
- [ ] 3.10.1 Hold product funds in simulated escrow ledger.
- [ ] 3.10.2 Allocate commission to platform wallet.
- [ ] 3.10.3 Allocate delivery fee to driver wallet.
- [ ] 3.10.4 Settle seller net amount (not gross).

### 3.11 Simulated Escrow Rules
- [ ] 3.11.1 Implement escrow state `held`.
- [ ] 3.11.2 Implement escrow state `releasable`.
- [ ] 3.11.3 Implement escrow state `released`.
- [ ] 3.11.4 Implement escrow state `canceled/refunded` if applicable.
- [ ] 3.11.5 Ensure ledger operations are auditable.
- [ ] 3.11.6 Ensure ledger operations are reversible via transaction history.

### 3.12 Data Model Targets
- [ ] 3.12.1 `counties`
- [ ] 3.12.2 `subcounties`
- [ ] 3.12.3 `wards`
- [ ] 3.12.4 `processing_rooms`
- [ ] 3.12.5 `users` (role-based)
- [ ] 3.12.6 `products` (raw/processed fields)
- [ ] 3.12.7 `orders` (type + actor bindings)
- [ ] 3.12.8 `escrow_wallets` or escrow-ledger equivalent
- [ ] 3.12.9 `seller_wallets` or role-wallet equivalent
- [ ] 3.12.10 `driver_wallets` or role-wallet equivalent
- [ ] 3.12.11 `notifications_log`

### 3.13 API and Validation
- [ ] 3.13.1 Enforce hierarchy consistency server-side.
- [ ] 3.13.2 Enforce sub-county order isolation server-side.
- [ ] 3.13.3 Enforce mandatory onboarding fields per actor.
- [ ] 3.13.4 Enforce processed-order validation before placement.

## 4. Implementation Batches
- [ ] 4.1 Batch 1: Schema and model foundations.
- [ ] 4.2 Batch 2: Identity and onboarding.
- [ ] 4.3 Batch 3: Order orchestration.
- [ ] 4.4 Batch 4: Notifications and settlement.
- [ ] 4.5 Batch 5: Hardening and test coverage.

## 5. Definition of Done
- [ ] 5.1 Kenya-only constraints are enforced by validation and business logic.
- [ ] 5.2 Cross-sub-county orders are blocked.
- [ ] 5.3 Raw and processed paths are both functional.
- [ ] 5.4 Simulated escrow transitions are deterministic and auditable.
- [ ] 5.5 Notification events are logged and dispatched for required actors.
- [ ] 5.6 Critical workflows have automated test coverage.
