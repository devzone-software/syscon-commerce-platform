package pe.syscon.invoice.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.invoice.domain.XmlDocument;
public interface XmlDocumentRepository extends JpaRepository<XmlDocument,UUID> {Optional<XmlDocument> findByInvoiceId(UUID invoiceId);}
