package pe.syscon.payment.application;

import java.math.BigDecimal;
import java.util.UUID;

/** Port for initiating a payment with a provider. */
public interface PaymentProvider {
    String name();
    Initiation initiate(UUID orderId, BigDecimal amount);
    record Initiation(String externalReference, String paymentUrl) {}
}
