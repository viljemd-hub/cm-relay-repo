# providers/

Tu bodo živeli konkretni adapterji (`ChannexAdapter`, `Beds24Adapter`, `WuBookAdapter`,
`BookingNativeAdapter`, `AirbnbNativeAdapter`, ...), vsak implementira
`CmConnectivityProviderAdapter.php`. Izbira prvega providerja je odprta (glej brief §12/§15).

Dolgoročno bodo adapterji implementirali tudi `reconcileReservations()`,
`reconcileAvailability()`, `reconcileRates()`, `reconcileRestrictions()`
(addendum §23) — brez spreminjanja obstoječega interfacea zdaj, ker nima
potrošnika, dokler ni izbran provider.

**Not yet implemented.**
