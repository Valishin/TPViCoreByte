# VERI*FACTU — plan, decisiones y dudas (borrador para revisión)

Estado: **pendiente de OK**. No se ha escrito código de esta fase.

Abreviaturas de las citas (todas las fuentes están en `docs/verifactu/oficial/`, ver `LEEME.md`):

| Cita | Documento |
|---|---|
| RRSIF | Reglamento aprobado por el RD 1007/2023 (consolidado) |
| OM | Orden HAC/1177/2024 (consolidada) |
| ROF | Reglamento de facturación, RD 1619/2012 |
| SW | Descripción de los servicios web, v1.0.3 (28/07/2025) |
| HUELLA | Especificaciones de la huella, v0.1.2 (27/08/2024) |
| QR | Especificaciones del código QR, v0.5.0 (10/12/2025) |
| VAL | Validaciones y errores, v1.2.2 (08/04/2026) |
| FAQ | Aclaraciones a dudas de desarrolladores, v1.3 (04/12/2025) |
| DR | Diseños de registro, v1.0 (28/10/2024) — `DsRegistroVeriFactu.xlsx` |
| XSD | Esquemas `tikeV1.0` (SuministroLR, SuministroInformacion, RespuestaSuministro, ConsultaLR, RespuestaConsultaLR) |

Plazos vigentes (FAQ 1, RDL 15/2025): sistemas adaptados antes del **1/1/2027** (contribuyentes del
Impuesto sobre Sociedades) y del **1/7/2027** (resto). Los servicios VERI*FACTU de la AEAT están en
producción desde el 23/04/2025.

---

## 1. Arquitectura

```
Emisión (TicketIssuer, RectificationService, InvoiceExchanger, AnnulmentService)
  └─ misma transacción ─► FiscalRecordGenerator ─► fiscal_records (inmutable, encadenado)
                                                      │ afterCommit
                                                      ▼
                         cola `verifactu` (1 worker) ─► VerifactuSender ─► AEAT (SOAP 1.1, TLS mutuo)
                                                      │                      │
                                  verifactu_submissions (cada envío: XML, respuesta, CSV)
                                  fiscal_record_states (estado por registro, mutable)
```

- **`fiscal_records` es inmutable** (trigger igual que `tickets`): guarda el XML exacto del registro
  (`RegistroAlta` o `RegistroAnulacion`), su huella, la del anterior y los campos de la huella. El
  estado de envío NO está en esa tabla: va en `fiscal_record_states` (estado, intentos, último
  error) y cada envío en `verifactu_submissions` (XML enviado, respuesta, CSV, `TiempoEsperaEnvio`).
  Motivo: el registro no se puede alterar una vez generado (SW 9.1.2: «El registro original no se
  puede modificar, por lo que permanecerá inalterado»).
- Una sola cadena por instalación y obligado (OM 7.c), con una fila "cabeza de cadena" bloqueada
  `FOR UPDATE` al generar, para que los registros se encadenen en orden cronológico de generación
  (OM 7.a, FAQ 15.4).

## 2. Decisiones (con su cita)

### 2.1 Registro y huella
| # | Decisión | Base |
|---|---|---|
| D1 | Se genera el `RegistroAlta` **en la misma transacción** que el documento, antes de imprimirlo. Si falla, no hay documento. | RRSIF 9 («de forma simultánea o inmediatamente anterior a la expedición»); FAQ 5 (no puede haber facturas sin RF ni RF sin factura). |
| D2 | Huella SHA-256, cadena `campo=valor&…` en el orden oficial, UTF-8, hexadecimal en mayúsculas. Alta: IDEmisorFactura, NumSerieFactura, FechaExpedicionFactura, TipoFactura, CuotaTotal, ImporteTotal, Huella (anterior), FechaHoraHusoGenRegistro. Anulación: IDEmisorFacturaAnulada, NumSerieFacturaAnulada, FechaExpedicionFacturaAnulada, Huella, FechaHoraHusoGenRegistro. | HUELLA 3 y 5; OM 13; DR lista L12 (`01` = SHA-256). **Ya comprobado: los 3 ejemplos oficiales (HUELLA 6.1–6.3) dan exactamente 3C464DAF…, F7B94CFD…, 177547C0….** |
| D3 | Importes con 2 decimales y punto (`12.35`, `-5.00`), sin ceros a la izquierda. | SW 6.8; HUELLA 3 (indiferente 1 o 2 decimales, usamos siempre 2 para que XML y huella coincidan). |
| D4 | `FechaHoraHusoGenRegistro` = instante de generación en Europe/Madrid con su desfase (`+01:00`/`+02:00`). `FechaExpedicionFactura` = fecha de emisión (dd-mm-aaaa, Madrid). | OM 7.e y 7.g; DR (formato ISO 8601). |
| D5 | Primer registro de la instalación: `PrimerRegistro=S`, sin `RegistroAnterior`, pero con su propia huella. | OM 7.b; HUELLA 5. |
| D6 | `NumSerieFactura` = `full_number` (T-26-000001…): solo ASCII 32–126 y sin `" ' < > =`. | VAL 3.1.3.1; ya es el snapshot del documento. |
| D7 | Sin firma XAdES ni registro de eventos: el sistema es **solo VERI*FACTU** (`TipoUsoPosibleSoloVerifactu=S`). | RRSIF 16.3; DR («Signature … no para remisión voluntaria»); FAQ 15 nota 1 (un SIF solo VERI*FACTU no está obligado al registro de eventos); OM 3 (no aplican 6.b–f, 7.f, 7.h–j, 8 y 9). |
| D8 | Aun así: comando y página para verificar la cadena (recalcular todas las huellas y enlaces) y alarma persistente si se rompe. Comprobación del registro anterior antes de generar el siguiente, **sin bloquear nunca la facturación**. | Voluntario (OM 3 exime de 6.e y 7.i); FAQ 15.2 («la facturación NUNCA debe interrumpirse»). |

### 2.2 Contenido por tipo
| # | Tipo | Contenido | Base |
|---|---|---|---|
| D9 | **F2** ticket | Sin `Destinatarios`. `FacturaSinIdentifDestinatarioArt61d` no se informa (N). Límite 3.000 € ya aplicado. | VAL 3.1.3.13 (F2/R5 no admiten destinatarios) y 15.8 (3.000 € + 10 € de margen). |
| D10 | **F1** factura directa | `Destinatarios/IDDestinatario` con `NIF` (NIF/NIE/CIF españoles) o `IDOtro` (NIF-IVA UE → IDType `02`; pasaporte → `03`; otro documento → `04`/`06`). | VAL 3.1.3.13; DR L7. |
| D11 | **F3** canje | `FacturasSustituidas/IDFacturaSustituida` = ticket canjeado; destinatario obligatorio; `FechaOperacion` = fecha del ticket si es distinta. | RRSIF 10.1.h; FAQ 27 («debe incorporarse … en el bloque FacturasSustituidas»); VAL 3.1.3.5 y 3.1.3.7. |
| D12 | **R5** rectificativo de ticket | `TipoRectificativa`: `I` (diferencias, importes en negativo) o `S` (sustitución, con `ImporteRectificacion` = base y cuota del ticket rectificado). `FacturasRectificadas` con el ticket. Sin destinatario. | DR L3; VAL 3.1.3.3, 3.1.3.4 y 3.1.3.6; FAQ 27 (ejemplo R5, TipoRectificativa I). |
| D13 | **R1–R4** rectificativa de factura completa (F1) | Igual que R5 pero con destinatario. R1 = errores fundados en derecho (p. ej. tipo de IVA) y art. 80 Uno, Dos y Seis LIVA (devoluciones, descuentos, resolución); R2 = concurso (80.Tres); R3 = incobrables (80.Cuatro); R4 = resto (p. ej. datos del destinatario). **Cierra la duda R1/R4** con el texto de la lista. | DR lista L2. Ver duda Q2 (confirmar con el asesor). |
| D14 | Desglose: un `DetalleDesglose` por tipo de IVA; `Impuesto` 01 (IVA), `ClaveRegimen` 01 (general), `CalificacionOperacion` S1, `TipoImpositivo` (0/2/4/5/7,5/10/21), base y cuota del snapshot `vat_breakdown`. Los gastos de envío entran en el tipo que se les aplicó. Descuentos e invitaciones ya están restados en la base. Las propinas no forman parte de la factura. | RRSIF 10.1.k y m; VAL 15.1, 15.4, 15.7; FAQ 20 (ImporteTotal = base tras descuentos + cuota; lo que no es factura no entra). |
| D15 | `CuotaTotal` = Σ cuotas; `ImporteTotal` = Σ (base + cuota). Cuadran con el ticket por construcción. | VAL 16 y 17 (margen ±10 € solo genera aviso). |
| D16 | `DescripcionOperacion` (obligatoria): fija por tipo de pedido — «Servicio de restauración» (mesa/barra), «Venta de comida para llevar», «Venta de comida a domicilio». | RRSIF 10.1.i; DR (Alfanumérico 500). |
| D17 | `Macrodato`, `Cupon`, `EmitidaPorTerceroODestinatario`, `Tercero`, recargo de equivalencia: no aplican (no se informan). | VAL 3.1.3.10, 14, 11. |

### 2.3 Anulación frente a rectificativa
Regla general: si la operación existió, **nunca se anula**; se rectifica (FAQ 17.2.a y d: «todas las
facturas emitidas, en la medida en que respondan a operaciones realmente efectuadas … no pueden
anularse»). La anulación es para facturas emitidas por error, sin operación real (RRSIF 11.1; FAQ 17.2.d
y 11, facturas de prueba).

| Caso en el TPV | Qué procede | Base |
|---|---|---|
| Caja → Anular (cobro erróneo, devolución, producto en mal estado) | **Rectificativo R5** (o R1 si es factura F1), como ahora | FAQ 17.2.a |
| Ticket **duplicado** o emitido **por error / de prueba** (no hubo venta) | **RegistroAnulacion** (nueva acción «Anular por error», con autorización) | RRSIF 11.1; FAQ 17.2.d y 11; SW 9.2.1 |
| Pedido a domicilio **no entregado**, sin pago previo | **RegistroAnulacion** (recomendado): no hubo entrega y el ticket no llegó al cliente | FAQ 17 (nota 2.d: si no se entregó al cliente, «favorecería la consideración de … expedición fallida») — **duda Q3** |
| No entregado **con pago anticipado** (ya cobrado) | **R5** (el anticipo devengó IVA) | RRSIF 10.1.e (pago anticipado); LIVA 75.Dos — **duda Q3** |
| Corrección de destinatario en **F1** | **R4 por sustitución** (como ahora en la serie FR) | ROF 15.1; FAQ 17.2.a |
| Corrección de destinatario en **F3** | **F3 en negativo + F3 nueva** (no R1–R4) | FAQ 27.2, caso 1 — **cambio C1** |
| Devolución de un ticket ya canjeado | **R5 del ticket + F3 de ese R5** | FAQ 27.1 y 27.2, casos 2 y 3 — **cambio C2** |
| Dato interno del registro mal (no visible en la factura) | **Alta de subsanación** (`Subsanacion=S`) | FAQ 17.2.b; SW 9.1.2 |

### 2.4 Envío
| # | Decisión | Base |
|---|---|---|
| D18 | Servicio `RegFactuSistemaFacturacion`, SOAP 1.1 document/literal, UTF-8, TLS con certificado cualificado. Endpoints del WSDL: pruebas `prewww1.aeat.es`, producción `www1.agenciatributaria.gob.es`; con **certificado de sello**, `prewww10` / `www10`. Entorno por defecto: **pruebas**. | SW 4.2, 4.3, 7 y 8; WSDL (puertos SistemaVerifactu*). |
| D19 | Control de flujo: un único worker en la cola `verifactu`. Envía cuando han pasado `t` segundos desde el envío anterior (60 al empezar; luego el `TiempoEsperaEnvio` de cada respuesta) **o** hay 1.000 pendientes, lo que ocurra antes. Lotes de hasta 1.000, del más antiguo al más reciente. | OM 16.2; SW 3 y 6.4.4.1. |
| D20 | Sin conexión: los registros se quedan pendientes; reintento al menos cada hora (el planificador lo intenta cada minuto respetando `t`); al enviarlos, `Cabecera/RemisionVoluntaria/Incidencia=S` en los lotes con registros retrasados por la incidencia. **Aviso visible desde el primer fallo**, con cuántos faltan, en caja y en /admin (además del aviso fuerte tras X horas que pediste). | OM 16.4 («deberá avisar … indicando cuántos faltan por remitir … a partir del momento en que se produzca la incidencia»); DR (campo Incidencia). |
| D21 | Respuestas: `Correcto` → aceptado; `AceptadoConErrores` → aceptado y marcado para **subsanar** (salvo los errores exceptuados: fecha-hora de generación); `Incorrecto` → rechazado, aviso y botón «Subsanar y reenviar» (`Subsanacion=S`, `RechazoPrevio=X`). Duplicado: si `EstadoRegistroDuplicado` es Correcta/AceptadaConErrores se da por aceptado (envío anterior sin respuesta); si es Anulada, alarma. | SW 3, 6.4.4 (RegistroDuplicado), 9.1.2, 9.1.3; VAL 4.3.1; DR cuadro A. |
| D22 | `SoapFault` `Client` → error de contenido: no se reintenta solo, alarma con el `faultstring`. `Server` o sin respuesta → reintento. | SW 5.1. |
| D23 | Se guarda el **CSV** de cada envío y el XML enviado y recibido (`verifactu_submissions`). | SW 3; OM 16.5. |
| D24 | Consulta (`ConsultaFactuSistemaFacturacion`) por ejercicio/periodo del obligado para conciliar: comando diario y botón en /admin; marca discrepancias (en AEAT y no aquí, o al revés). | SW 6.4 (máx. 10.000 por consulta, paginada). |
| D25 | Certificado .pfx/.p12 subido en Filament (solo admin), guardado cifrado con APP_KEY (certificado y contraseña); TLS mutuo con curl en memoria (sin escribir la clave en disco); aviso 30 días antes de caducar. Modos: «del propio obligado» y «de un representante (apoderado o colaborador social)»; el NIF del certificado se compara con el del obligado en el primer modo. | OM 4.b y 5; SW 4.1 (obligado, apoderado o colaborador social); FAQ 16. |
| D26 | Campo `Representante` de la cabecera: **no** se rellena por usar el certificado de un apoderado o colaborador; solo en la colaboración social «transitiva» (obligado → asesor → plataforma), con los datos del asesor. Se deja como dato opcional del modo «representante». | DR (Representante: «A rellenar solo en caso de que los registros … hayan sido generados por un representante/asesor»); SW 2.4 (v0.4.0). — **duda Q4** |

### 2.5 Sistema informático, activación y declaración
| # | Decisión | Base |
|---|---|---|
| D27 | `SistemaInformatico`: NombreRazon y NIF de iCOREBYTE, `NombreSistemaInformatico` (≤30), `IdSistemaInformatico` (2 caracteres A–Z sin Ñ o 0–9), `Version` (la de la release, fija en el código, no configurable), `NumeroInstalacion` (generado por el instalador: fecha-hora de instalación + aleatorio; nunca se repite), `TipoUsoPosibleSoloVerifactu=S`, `TipoUsoPosibleMultiOT=N`, `IndicadorMultiplesOT=N`. Productor, nombre e Id los pone el instalador (.env de root); el cliente no puede editarlos en /admin. | DR hoja 5; VAL 3.1.5; FAQ 4 (nº de instalación irrepetible; se recomienda fecha-hora); OM 2 (un obligado). |
| D28 | Ajuste «VERI*FACTU activo» + fecha de inicio, **desactivado por defecto**. Desde el primer envío efectivo **no se puede desactivar hasta el 31/12 de ese año**; la renuncia se comunica con `FechaFinVeriFactu` = 31-12-AAAA en los envíos, antes de acabar el año. | RRSIF 16.5; OM 17.2 y 17.3; VAL 3.1.1.3 (desde 2027, formato 31-12-20XX). |
| D29 | La cadena empieza al activar (`PrimerRegistro=S`). Los documentos anteriores no tienen registro; si luego se rectifican, el R5 los cita igualmente; si se anulan, `SinRegistroPrevio=S`. | OM 7.b; DR cuadro B (anulación sin registro previo). |
| D30 | QR y leyenda **al principio** del documento (hoy el hueco reservado está al final y hay que moverlo): texto «QR tributario:» encima, QR de 30–40 mm, nivel M, ISO/IEC 18004, margen ≥ 2 mm (6 mm recomendado), y debajo «VERI*FACTU» (tickets 58/80 mm) o «Factura verificable en la sede electrónica de la AEAT» (A4), con letra igual o mayor que el resto. URL `…/wlpl/TIKE-CONT/ValidarQR?nif=&numserie=&fecha=dd-mm-aaaa&importe=` (pruebas `prewww2.aeat.es`, producción `www2.agenciatributaria.gob.es`), parámetros con URL-encoding UTF-8, sin el parámetro `formato`. Solo con VERI*FACTU activo. | OM 20 y 21; QR 2, 3, 4, 5.1, 6 y 7; ejemplos del anexo de QR (anchos 44/57 mm). |
| D31 | En impresora térmica el QR se imprime como imagen ráster (calculada a 203 ppp para 35 mm) en lugar del comando QR nativo de la impresora: así es el mismo QR que se comprueba en los tests (se decodifica y se compara la URL) y su tamaño no depende del modelo. | QR 2 (tamaño 30–40 mm); petición de comprobar el QR. |
| D32 | Declaración responsable (borrador en `docs/verifactu/`, visible en «Acerca de» de la caja y de /admin) con los apartados 1.a–1.l en el orden de la OM. | OM 15 y RRSIF 13; ejemplo 1 de la AEAT («solo VERI*FACTU»). |
| D33 | En pantalla, el obligado siempre identificado (nombre comercial + razón social/NIF en «Acerca de»). | OM 2.d; FAQ 7. |

### 2.6 Otras
- Exportación fiscal: `registros_verifactu.csv` (un registro por fila con estado, huella, CSV del envío) + los XML. Las copias de seguridad ya incluyen la base de datos (tablas nuevas incluidas).
- Instalación y checklist: el certificado se guarda cifrado con la APP_KEY; sin ella una copia no permite recuperarlo (igual que el SMTP).
- `tpv:verifactu:test-send`: envía un registro de prueba, fuera de la cadena real, al entorno de pruebas con el certificado configurado y muestra la respuesta.

## 3. Cambios de comportamiento que necesitan tu OK

- **C1 — Corrección de destinatario en una factura de canje (F3).** Hoy: rectificativa R4 en la serie FR. Según la FAQ 27.2 (caso 1) debe hacerse con **una F3 en negativo + una F3 nueva**, ambas en la serie F y sustituyendo al mismo ticket. Supone permitir más de una F3 por ticket (hoy hay una clave única).
- **C2 — Devolución o rectificación de un ticket ya canjeado.** Hoy está bloqueado («rectifica la factura»). Según la FAQ 27.1 y 27.2 (casos 2 y 3) se hace **R5 del ticket + F3 de ese R5** para el cliente. Las facturas F3 de canje ya no tendrían rectificativas R1–R4 (las R1–R4 quedan para las F1 directas).
- **C3 — Nueva acción «Anular por error (no hubo venta)»**, distinta de «Anular» (que es rectificar): genera RegistroAnulacion, el documento queda en estado «anulado» y no cuenta en ventas ni IVA. Se quita «Ticket duplicado» de los motivos de rectificar.
- **C4 — «No entregado»**: anulación si no se había cobrado; R5 si estaba prepagado (pendiente de Q3).
- **C5 — QR al principio** del ticket, la factura y el PDF (no al final como estaba reservado).
- **C6 — Aviso desde el primer fallo de envío** (OM 16.4), además del aviso tras X horas.

## 4. Dudas normativas (no las invento: necesitan tu decisión o la del asesor)

- **Q1 — Régimen simplificado (módulos).** Muchos restaurantes tributan el IVA en régimen simplificado; el RRSIF 10.1.m obliga a indicarlo y la lista L8A tiene la clave **20 «Régimen simplificado»**. Propongo un ajuste «Régimen de IVA: general (01) / simplificado (20)» por local, por defecto 01. ¿Correcto? ¿Hay otros casos en vuestros clientes (p. ej. recargo de equivalencia no aplica a hostelería)?
- **Q2 — Frontera R1/R4.** Con el texto de la L2 propongo: R1 para devoluciones, descuentos posteriores, anulación parcial de la venta y tipo de IVA erróneo; R4 para datos del destinatario y otros errores formales. Confirmar con el asesor.
- **Q3 — «No entregado».** ¿Anulación (sin cobro previo) y R5 (con cobro previo), como en C4? La normativa no trata este caso de forma expresa.
- **Q4 — Representante.** Si iCOREBYTE envía como colaborador social con su certificado, ¿hay además un asesor del cliente que deba constar en `Representante`? Si no lo hay, el campo va vacío (D26).
- **Q5 — Activación frente a obligación.** Al ser un sistema «solo VERI*FACTU», a partir del 1/1/2027 (o 1/7/2027) un cliente con VERI*FACTU desactivado no cumpliría (no implementamos el modo «No VERI*FACTU»: firma y registro de eventos). Propuesta: hasta esa fecha, el ajuste es libre (desactivado por defecto); desde ella, **no se puede emitir sin VERI*FACTU activo** (o, si prefieres, solo aviso). ¿Bloqueo o aviso? ¿La fecha la elijo por cliente (IS sí/no)?
- **Q6 — Restaurar una copia en otro equipo.** ¿Es la misma instalación (sigue la cadena con el mismo `NumeroInstalacion`) o una nueva (número nuevo y `PrimerRegistro`)? La FAQ 4 dice que reinstalar implica número nuevo, pero no habla de restaurar. Propuesta: restaurar en el mismo local = misma instalación; equipo nuevo = el instalador genera número nuevo y la cadena continúa con `RegistroAnterior` del último registro (no se rompe).
- **Q7 — Importe negativo en el QR.** La especificación del QR no dice nada de importes negativos (rectificativos). Propuesta: `importe=-5.00`. Lo comprobaré en el entorno de pruebas con el cotejo.
- **Q8 — Criterio de «Incidencia».** Propuesta: `Incidencia=S` en los envíos que contengan registros que no se pudieron enviar en su primera oportunidad por un fallo técnico (sin conexión, error del servidor).
- **Q9 — Precuentas.** La FAQ 11 dice que las prefacturas/proformas que se expidan deben conservarse de forma inalterable (LGT 29.2.j). Hoy la «cuenta» se imprime y no se guarda como documento. ¿Las conservamos (registro inmutable de cada precuenta impresa)?
- **Q10 — Datos del productor.** Necesito de ti: razón social y NIF de iCOREBYTE, dirección postal, nombre comercial del sistema (≤30 caracteres), código de 2 caracteres y lugar de firma de la declaración.

## 5. Bloques (commits) tras el OK

1. Esquema: `fiscal_records` (rehecha, inmutable con trigger), estados, envíos, cabeza de cadena, ajustes, datos del SIF y certificado; documento con estado «anulado».
2. Generación: huella (tests con los 3 ejemplos oficiales), XML de alta y anulación para F1, F2, F3, R1–R5, validados contra los XSD oficiales; encadenamiento en la misma transacción.
3. Cambios C1–C4 (canje, anulación, no entregado) con sus registros.
4. QR y leyenda en ticket 80/58 mm, factura y PDF (tests decodificando el QR).
5. Certificado (subida, cifrado, caducidad), cliente SOAP con TLS mutuo, entornos.
6. Cola `verifactu`: control de flujo, sin conexión, respuestas, duplicados, SoapFault, subsanación (servicio AEAT simulado en los tests).
7. Consulta y conciliación; `tpv:verifactu:verify`; `tpv:verifactu:test-send`.
8. Filament: página VERI*FACTU (estado, pendientes, errores, detalle con XML y respuesta, reintento, conciliación); avisos en caja y /admin; «Acerca de» con la declaración responsable.
9. Exportación fiscal, copias, documentación (instalación, checklist, APP_KEY y certificado); borrador de declaración responsable.
10. Pruebas de extremo a extremo y resumen.
