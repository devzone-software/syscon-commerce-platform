package pe.syscon.supplier.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.supplier.domain.ScrapingJob;
public interface ScrapingJobRepository extends JpaRepository<ScrapingJob,UUID> { Optional<ScrapingJob> findTopBySupplierIdOrderByStartedAtDesc(UUID supplierId); }
