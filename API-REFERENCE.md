# EasyCheckout Dashboard API — Bauplan für das native Plugin

Alle Endpunkte JWT (`Authorization: Bearer <token>`), außer login/register/forgot/reset.
Basis-URL = `easycheckout_api_url` (prod `https://www.easycheckout.ch`).
JSON-Felder (design, paymentMethods, items) kommen geparst zurück. Preise in CHF (nicht Cents).

## Auth
- `POST /api/auth/login` {email,password} → {token, merchant{id,email,companyName,plan,stripe*}}
- `POST /api/auth/register` {email,password(>=8),companyName?,plan} → {token, merchant}
- `GET /api/auth/me` → {merchant{... +planLimits,stripeStatusState,stripeRequirementsDue,street,city,iban,qrPaymentEnabled,whitelabelAddon,statementDescriptor}}
- `PUT /api/auth/password` {currentPassword,newPassword}
- `PUT /api/auth/profile` {companyName,email,street,postalCode,city,phone,vatNumber}
- `PUT /api/auth/qr-settings` {iban,qrPaymentEnabled}
- `PUT /api/auth/statement-descriptor` {statementDescriptor(5-22)}
- `POST /api/auth/forgot-password` {email}; `POST /api/auth/reset-password` {token,password}

## Checkouts
- `GET /api/checkouts` → {checkouts:[{id,name,description,slug,design,vatEnabled,vatRate,vatInclusive,paymentMethods,currency,isActive,createdAt,_count{products,orders}}]}
- `POST /api/checkouts` {name,description?,slug} → {checkout} (201). Plan-Limit; defaults primary #4F46E5, pm ['card','twint'], vat 8.1.
- `GET /api/checkouts/[id]` → {checkout (+products)}
- `PUT /api/checkouts/[id]` {name,description,slug,isActive,design,vatEnabled,vatRate,vatInclusive,paymentMethods,currency,successUrl,cancelUrl,qrPaymentEnabled} → {checkout}
- `DELETE /api/checkouts/[id]` → {success}
- `GET /api/checkouts/[id]/products` → {products:[{id,name,description,price,imageUrl(base64),isActive,sortOrder,maxPerCustomer,maxTotal,trackInventory,inventoryCount}]}
- `POST /api/checkouts/[id]/products` {name,description,price,imageUrl?,isActive,sortOrder,maxPerCustomer,maxTotal,trackInventory,inventoryCount} → {product} (201)

## Products
- `PUT /api/products/[id]` {…wie create} → {product}
- `DELETE /api/products/[id]` → {success}
- `POST /api/products/[id]/image` FormData{image (<=2MB)} → {imageUrl(base64),product}
- `DELETE /api/products/[id]/image` → {success}

## Orders
- `GET /api/orders?limit=20&page=1&status=&checkoutId=` → {orders:[{id,checkoutName,total,platformFee,merchantPayout,paymentStatus,paymentMethod,customer*,items[],paidAt,createdAt}],pagination{total,page,limit,pages}}
- `POST /api/orders/sync` → {synced,updated,errors}
- `POST /api/orders/[id]/refund` {amount?} → {success,refund,orderStatus}

## Payments
- `GET /api/payments?limit&page&status` → {orders[+failureReason],balance{available[],pending[]},stats{totalGross,totalPlatformFees,totalNet,…},pagination}
- `POST /api/payments/topup` {amount(>=1),currency} → {clientSecret,paymentIntentId}

## Customers
- `GET /api/customers` → {customers:[{id,email,name,phone,…,orderCount,totalSpent,lastOrderDate,isManual}]}
- `POST /api/customers` {email,name,phone,street,postalCode,city,country,notes} → {customer}
- `GET|PUT|DELETE /api/customers/[id]`

## Invoices
- `GET /api/invoices` → {invoices:[{id,invoiceNumber,customer*,items[],subtotal,vatRate,vatAmount,total,currency,dueDate,status,publicToken,sentAt,paidAt,reminderCount,createdAt}]}
- `POST /api/invoices` {customerId?,customerEmail,customerName,customer*,items[{quantity,price,description}],vatRate,dueDate,notes,currency} → {invoice}
- `GET /api/invoices/[id]` → {invoice,merchant{companyName,email,iban,qrPaymentEnabled}}
- `PUT|DELETE /api/invoices/[id]`
- `POST /api/invoices/[id]/send` → {invoiceUrl}; `POST .../preview` → {previewUrl}; `POST .../reminder`

## Emails / Templates / Logs
- `GET /api/emails` → {templates:[{id,type,name,subject,body,isActive}]}; `POST /api/emails` (upsert by type); `GET|PUT|DELETE /api/emails/[id]`
- `GET /api/email-logs?page&limit&status&type&search` → {emails[],pagination,stats}

## Marketing / Subscribers
- `GET /api/subscribers?page&limit&search&filter` → {subscribers[],pagination,stats}; `POST /api/subscribers` {email,name}; `DELETE /api/subscribers` {ids[]}
- `GET /api/marketing` → {campaigns[],pagination}; `POST /api/marketing` {name,subject,body,campaignType}; `POST /api/marketing/[id]/send`

## Merchant / Settings
- `POST /api/merchant/logo` FormData{logo} → {logoUrl(base64)}; `DELETE /api/merchant/logo`
- `GET /api/merchant/webhooks`; `POST` {url,events[],isActive}; `PATCH?id=`; `DELETE?id=`
- Whitelabel: `GET|POST|DELETE /api/email-domain` (+`/verify`,`/sender`); `GET|POST|DELETE /api/checkout-domain` (+`/verify`)

## Onboarding / Stripe Connect (KYC, mehrstufig)
- `GET /api/stripe/connect` → {stripeAccountId,chargesEnabled,payoutsEnabled,detailsSubmitted,onboardingComplete,currentStep('business'|'personal'|'bank'|'terms'|'verification'|'pending'|'complete'),requirements[]}
- `POST /api/stripe/connect` {origin?} → erstellt Custom-Account
- `POST /api/stripe/connect/business` {businessType,companyName?,taxId?,address,website?|productDescription?,phone?,industry(MCC)}
- `POST /api/stripe/connect/person` {firstName,lastName,dob{d,m,y},address,isOwner,percentOwnership?,title?}
- `GET|POST|DELETE /api/stripe/connect/persons` (Liste/anlegen/löschen ?personId=)
- `POST /api/stripe/connect/confirm-owners` {owners?,directors?,executives?}
- `POST /api/stripe/connect/document` FormData{front,back?}; `POST /api/stripe/connect/company-document` FormData{document}
- `POST /api/stripe/connect/bank` {iban,accountHolderName}
- `POST /api/stripe/connect/terms` → {chargesEnabled,…} ODER {requiresRedirect,redirectUrl}
- `POST /api/stripe/connect/onboarding-link` {origin?} → {url} (Stripe-hosted → NEUER TAB) | {redirectUrl}
- `GET /api/stripe/account-status` → {hasAccount,status{state,deadline,summary},selfServe,chargesEnabled,payoutsEnabled,counts,capabilities,tasks[{taskKey,title,description,docType?,personIds?,severity}]}
- `GET /api/stripe/capabilities` → {capabilities{card,twint,apple_pay,google_pay},accountRequirements,chargesEnabled,…}

## Billing / Subscription
- `POST /api/subscription/checkout` {plan} → {clientSecret,subscriptionId,amount} | {success,plan:'free'}
- `POST|DELETE /api/subscription/addon` {addon:'whitelabel'}; `GET /api/subscription/verify?session_id=`

## Dashboard / Tasks / Support
- `GET /api/dashboard/stats` → {revenue,ordersCount,checkoutsCount,conversionRate} (30 Tage)
- `GET /api/tasks` → {tasks[{taskKey,title,isCompleted}]}; `PATCH /api/tasks` {taskKey,isCompleted}
- `GET|POST /api/support/tickets`; `GET|PATCH /api/support/tickets/[id]`

## Hinweise
- **Stripe-Redirects** (`onboarding-link.url`, `terms.redirectUrl`) → im iFrame/embed in NEUEM Tab öffnen.
- **Datei-Uploads** (Produktbild, Logo, KYC-Docs) = multipart FormData → eigener Proxy-Pfad nötig (der JSON-Proxy reicht nicht).
- **Screens (20)**: Übersicht, Checkouts(Liste/Neu/Detail/Produkte), Bestellungen, Zahlungen, Kunden, Rechnungen(Liste/Neu/Detail), E-Mails(Vorlagen/Logs), Marketing, Einstellungen, Konto, Billing/Addons, Support, Webhooks, Onboarding.
