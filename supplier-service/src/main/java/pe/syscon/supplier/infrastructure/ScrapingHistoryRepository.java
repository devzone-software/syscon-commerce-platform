package pe.syscon.supplier.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.supplier.domain.ScrapingHistory;
public interface ScrapingHistoryRepository extends JpaRepository<ScrapingHistory,UUID> { List<ScrapingHistory> findByJobIdOrderByOccurredAtAsc(UUID jobId); }
