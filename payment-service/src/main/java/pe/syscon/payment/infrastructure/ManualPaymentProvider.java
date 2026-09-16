package pe.syscon.payment.infrastructure;

import java.math.BigDecimal;
import java.util.UUID;
import org.springframework.stereotype.Component;
import pe.syscon.payment.application.PaymentProvider;

@Component
public class ManualPaymentProvider implements PaymentProvider {
    @Override public String name() { return "MANUAL"; }
    @Override public Initiation initiate(UUID orderId, BigDecimal amount) { return new Initiation(null, null); }
}
