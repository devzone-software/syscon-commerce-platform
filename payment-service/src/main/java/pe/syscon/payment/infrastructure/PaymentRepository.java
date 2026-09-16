package pe.syscon.payment.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.payment.domain.Payment;
public interface PaymentRepository extends JpaRepository<Payment,UUID> {Optional<Payment> findByOrderIdAndProvider(UUID orderId,String provider);}
