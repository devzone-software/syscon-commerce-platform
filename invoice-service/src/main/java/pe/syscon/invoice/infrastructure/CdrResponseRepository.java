package pe.syscon.invoice.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.invoice.domain.CdrResponse;
public interface CdrResponseRepository extends JpaRepository<CdrResponse,UUID> {Optional<CdrResponse> findByInvoiceId(UUID invoiceId);}
