# Dudas para el asesor fiscal — TPV de hostelería con VERI*FACTU

Somos los fabricantes de un programa de caja (TPV) para bares y restaurantes que envía
cada ticket y cada factura a Hacienda en el momento (sistema VERI*FACTU). Hay cuatro
puntos en los que la normativa no es del todo clara y queremos vuestra confirmación.
Entre paréntesis, la referencia normativa por si queréis comprobarla.

---

## 1. Régimen de IVA del restaurante

Cada envío a Hacienda debe indicar en qué régimen de IVA está el negocio (Real Decreto
1007/2023, artículo 10.1.m). Hemos previsto dos opciones, que se eligen al instalar el
programa en cada local:

- **Régimen general** (lo más habitual en sociedades).
- **Régimen simplificado** (módulos), frecuente en autónomos de hostelería.

**Pregunta:** ¿es correcto que el restaurante en módulos se declare como «régimen
simplificado» en todos sus tickets y facturas? ¿Hay algún otro caso entre clientes de
hostelería que debamos prever (por ejemplo, un local que también vende productos de
tienda)?

---

## 2. Tipos de factura rectificativa

Cuando hay que corregir una factura completa (con datos del cliente), Hacienda distingue
varios tipos de rectificativa. Nosotros aplicamos este criterio, según la lista oficial
de tipos de factura:

| Situación | Tipo |
|---|---|
| El cliente devuelve algo, se aplica un descuento después, se anula parte de la venta o se aplicó mal el tipo de IVA | **R1** (error fundado en derecho y art. 80 Uno, Dos y Seis de la Ley del IVA) |
| Cliente en concurso de acreedores | **R2** (art. 80.Tres) |
| Deuda incobrable | **R3** (art. 80.Cuatro) |
| Datos del cliente equivocados (nombre, NIF, dirección) u otros errores sin cambio de importes | **R4** (resto) |

En los tickets normales (sin datos del cliente) la rectificación es siempre **R5**.

**Pregunta:** ¿estáis de acuerdo con esta clasificación, en particular con usar R1 para
las devoluciones y descuentos posteriores, y R4 para corregir los datos del cliente?

---

## 3. Pedido a domicilio que no se llega a entregar

El ticket se imprime cuando el repartidor sale con el pedido. A veces el cliente no está o
rechaza el pedido y la venta no llega a producirse. Hacienda permite «anular» un ticket
emitido por error cuando no hubo venta, pero no permite anular ventas reales: esas se
corrigen con un rectificativo.

Nuestro criterio:

- **Si el cliente no había pagado nada:** se **anula** el ticket (no hubo venta ni entrega).
- **Si el pedido estaba pagado por adelantado** (por ejemplo, con tarjeta al encargarlo):
  se hace un **rectificativo** que devuelve el importe, porque el cobro anticipado ya
  generó el IVA.

**Pregunta:** ¿es correcto este criterio? (Real Decreto 1007/2023, artículo 11.1; Ley del
IVA, artículo 75.Dos para los pagos anticipados.) Lo hemos dejado preparado para poder
cambiarlo fácilmente si nos indicáis otra cosa.

---

## 4. Cambio de ordenador o restauración de una copia

Cada instalación del programa tiene un «número de instalación» que no se puede repetir
nunca (si se reinstala el programa, debe tener un número nuevo). Hacienda no aclara qué
pasa al **restaurar una copia de seguridad**.

Nuestro criterio:

- **Restaurar en el mismo ordenador** (por ejemplo, tras un fallo del disco): se mantiene
  el número de instalación y los envíos continúan como si nada.
- **Restaurar en un ordenador nuevo:** se trata como una reinstalación: número de
  instalación nuevo, pero la cadena de registros continúa desde el último ticket enviado,
  sin empezar de cero.

**Pregunta:** ¿os parece correcto este criterio?
