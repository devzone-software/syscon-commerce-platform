package pe.syscon.invoice.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.invoice.domain.Invoice;
public interface InvoiceRepository extends JpaRepository<Invoice,UUID> {
 boolean existsByOrderIdAndTypeIn(UUID orderId,Collection<String> types);
 Optional<Invoice> findBySerialNumber(String serialNumber);
}
